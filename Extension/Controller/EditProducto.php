<?php
/**
 * This file is part of StockAvanzado plugin for FacturaScripts
 * Copyright (C) 2020-2024 Carlos Garcia Gomez <carlos@facturascripts.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace FacturaScripts\Plugins\StockAvanzado\Extension\Controller;

use Closure;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\DataSrc\Almacenes;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Lib\StockMovementManager;
use FacturaScripts\Dinamic\Lib\StockRebuild;
use FacturaScripts\Dinamic\Model\Stock;
use FacturaScripts\Dinamic\Model\ConteoStock;
use FacturaScripts\Dinamic\Model\LineaConteoStock;

/**
 * Description of EditProducto
 *
 * @author Carlos Garcia Gomez <carlos@facturascripts.com>
 * @author Jose Antonio Cuello <yopli2000@gmail.com>
 */
class EditProducto
{
    protected function changeStockAction(): Closure
    {
        return function () {
            $data = $this->request->request->all();

            $stock = new Stock();
            if (empty($data['code']) || false === $stock->loadFromCode($data['code'])) {
                Tools::log()->warning('record-not-found');
                return true;
            }

            $this->dataBase->beginTransaction();

            // creamos un nuevo conteo
            $conteo = new ConteoStock();
            $conteo->codalmacen = $stock->codalmacen;
            $conteo->observaciones = $data['mov-description'];
            if (false === $conteo->save()) {
                $this->dataBase->rollback();
                Tools::log()->warning('record-save-error');
                return true;
            }

            // añadimos una línea con la nueva cantidad
            $line = new LineaConteoStock();
            $line->idconteo = $conteo->idconteo;
            $line->idproducto = $stock->idproducto;
            $line->referencia = $stock->referencia;
            $line->cantidad = (float)$data['mov-quantity'];
            if (false === $line->save()) {
                $this->dataBase->rollback();
                Tools::log()->warning('record-save-error');
                return true;
            }

            // actualizamos el stock
            $stock->cantidad = (float)$data['mov-quantity'];
            if (false === $stock->save()) {
                $this->dataBase->rollback();
                Tools::log()->warning('record-save-error');
                return true;
            }

            $this->dataBase->commit();
            Tools::log()->notice('record-updated-correctly');
            return true;
        };
    }

    protected function createViews(): Closure
    {
        return function () {
            // marcamos la columna de cantidad en el stock como no editable
            $this->views['EditStock']->disableColumn('quantity', false, 'true');

            if ($this->user->admin) {
                $this->addButton('EditProducto', [
                    'action' => 'rebuild-stock',
                    'color' => 'warning',
                    'confirm' => true,
                    'icon' => 'fas fa-magic',
                    'label' => 'rebuild-stock'
                ]);
            }

            // añadimos las nuevas pestañas
            $this->createViewsMovements();
        };
    }

    protected function createViewsMovements(): Closure
    {
        return function ($viewName = 'ListMovimientoStock') {
            $this->addListView($viewName, 'MovimientoStock', 'movements', 'fas fa-truck-loading');
            $this->views[$viewName]->addOrderBy(['fecha', 'hora', 'id'], 'date', 2);
            $this->views[$viewName]->addOrderBy(['cantidad'], 'quantity');
            $this->views[$viewName]->searchFields = ['documento', 'referencia'];

            // filtros
            $this->views[$viewName]->addFilterPeriod('fecha', 'date', 'fecha');
            $this->views[$viewName]->addFilterNumber('cantidadgt', 'quantity', 'cantidad', '>=');
            $this->views[$viewName]->addFilterNumber('cantidadlt', 'quantity', 'cantidad', '<=');

            // desactivamos la columna de producto
            $this->views[$viewName]->disableColumn('product');

            // desactivamos la columna de almacén si solo hay uno
            if (count(Almacenes::codeModel(false)) <= 1) {
                $this->views[$viewName]->disableColumn('warehouse');
            } else {
                $this->views[$viewName]->addFilterSelect('codalmacen', 'warehouse', 'codalmacen', Almacenes::codeModel());
            }

            // desactivamos los botones de nuevo, eliminar y checkbox
            $this->setSettings($viewName, 'btnDelete', false);
            $this->setSettings($viewName, 'btnNew', false);
            $this->setSettings($viewName, 'checkBoxes', false);

            if ($this->user->admin) {
                $this->addButton($viewName, [
                    'action' => 'rebuild-movements',
                    'color' => 'warning',
                    'confirm' => true,
                    'icon' => 'fas fa-magic',
                    'label' => 'rebuild-movements'
                ]);
            }
        };
    }

    protected function execPreviousAction(): Closure
    {
        return function ($action) {
            if ($action === 'change-stock') {
                $this->changeStockAction();
            } elseif ($action === 'rebuild-movements') {
                $this->rebuildMovementsAction();
            } elseif ($action === 'rebuild-stock') {
                $this->rebuildStockAction();
            }
        };
    }

    protected function loadData(): Closure
    {
        return function ($viewName, $view) {
            $id = $this->getViewModelValue('EditProducto', 'idproducto');

            switch ($viewName) {
                case 'ListMovimientoStock':
                    $where = [new DataBaseWhere('idproducto', $id)];
                    $view->loadData('', $where);
                    $this->setSettings($viewName, 'active', $view->model->count($where) > 0);
                    break;
            }
        };
    }

    protected function rebuildMovementsAction(): Closure
    {
        return function () {
            if (false === $this->user->admin) {
                Tools::log()->warning('not-allowed-modify');
                return;
            } elseif (false === $this->validateFormToken()) {
                return;
            }

            $product = $this->getModel();
            if (false === $product->loadFromCode($this->request->get('code'))) {
                return;
            }

            StockMovementManager::rebuild($product->idproducto);
        };
    }

    protected function rebuildStockAction(): Closure
    {
        return function () {
            if (false === $this->user->admin) {
                Tools::log()->warning('not-allowed-modify');
                return;
            } elseif (false === $this->validateFormToken()) {
                return;
            }

            $product = $this->getModel();
            if (false === $product->loadFromCode($this->request->get('code'))) {
                return;
            }

            StockRebuild::rebuild($product->idproducto);
        };
    }
}
