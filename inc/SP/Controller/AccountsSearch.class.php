<?php
/**
 * sysPass
 *
 * @author    nuxsmin
 * @link      http://syspass.org
 * @copyright 2012-2015 Rubén Domínguez nuxsmin@syspass.org
 *
 * This file is part of sysPass.
 *
 * sysPass is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * sysPass is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with sysPass.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace SP\Controller;

defined('APP_ROOT') || die(_('No es posible acceder directamente a este archivo'));

use SP\Account\AccountSearch;
use SP\Account\AccountsSearchData;
use SP\Config\Config;
use SP\Core\ActionsInterface;
use SP\Core\Session;
use SP\Core\SessionUtil;
use SP\Core\Template;
use SP\Html\DataGrid\DataGrid;
use SP\Html\DataGrid\DataGridAction;
use SP\Html\DataGrid\DataGridActionType;
use SP\Html\DataGrid\DataGridData;
use SP\Html\DataGrid\DataGridHeaderSort;
use SP\Html\DataGrid\DataGridPager;
use SP\Html\DataGrid\DataGridSort;
use SP\Http\Request;
use SP\Storage\DBUtil;
use SP\Util\Checks;

/**
 * Clase encargada de obtener los datos para presentar la búsqueda
 *
 * @package Controller
 */
class AccountsSearch extends Controller implements ActionsInterface
{
    /**
     * Indica si el filtrado de cuentas está activo
     *
     * @var bool
     */
    private $filterOn = false;


    /** @var string */
    private $_sk = '';
    /** @var int */
    private $_sortKey = 0;
    /** @var string */
    private $_sortOrder = 0;
    /** @var bool */
    private $_searchGlobal = false;
    /** @var int */
    private $_limitStart = 0;
    /** @var int */
    private $_limitCount = 0;
    /** @var int */
    private $_queryTimeStart = 0;
    /** @var bool */
    private $_isAjax = false;

    /**
     * Constructor
     *
     * @param $template Template con instancia de plantilla
     */
    public function __construct(Template $template = null)
    {
        parent::__construct($template);

        $this->_queryTimeStart = microtime();
        $this->_sk = SessionUtil::getSessionKey(true);
        $this->view->assign('sk', $this->_sk);
        $this->setVars();
    }

    /**
     * Establecer las variables necesarias para las plantillas
     */
    private function setVars()
    {
        $this->view->assign('isAdmin', (Session::getUserIsAdminApp() || Session::getUserIsAdminAcc()));
        $this->view->assign('showGlobalSearch', Config::getConfig()->isGlobalSearch());

        // Comprobar si está creado el objeto de búsqueda en la sesión
        if (!is_object(Session::getSearchFilters())) {
            Session::setSearchFilters(new AccountSearch());
        }

        // Obtener el filtro de búsqueda desde la sesión
        $filters = Session::getSearchFilters();

        // Comprobar si la búsqueda es realizada desde el fromulario
        // de lo contrario, se recupera la información de filtros de la sesión
        $isSearch = (!isset($this->view->actionId));

        $this->_sortKey = ($isSearch) ? Request::analyze('skey', 0) : $filters->getSortKey();
        $this->_sortOrder = ($isSearch) ? Request::analyze('sorder', 0) : $filters->getSortOrder();
        $this->_searchGlobal = ($isSearch) ? Request::analyze('gsearch', 0) : $filters->getGlobalSearch();
        $this->_limitStart = ($isSearch) ? Request::analyze('start', 0) : $filters->getLimitStart();
        $this->_limitCount = ($isSearch) ? Request::analyze('rpp', 0) : $filters->getLimitCount();

        // Valores POST
        $this->view->assign('searchCustomer', ($isSearch) ? Request::analyze('customer', 0) : $filters->getCustomerId());
        $this->view->assign('searchCategory', ($isSearch) ? Request::analyze('category', 0) : $filters->getCategoryId());
        $this->view->assign('searchTxt', ($isSearch) ? Request::analyze('search') : $filters->getTxtSearch());
        $this->view->assign('searchGlobal', Request::analyze('gsearch', $filters->getGlobalSearch()));
        $this->view->assign('searchFavorites', Request::analyze('searchfav', $filters->isSearchFavorites()));
    }

    /**
     * @param boolean $isAjax
     */
    public function setIsAjax($isAjax)
    {
        $this->_isAjax = $isAjax;
    }

    /**
     * Obtener los datos para la caja de búsqueda
     */
    public function getSearchBox()
    {
        $this->view->addTemplate('searchbox');

        $this->view->assign('customers', DBUtil::getValuesForSelect('customers', 'customer_id', 'customer_name'));
        $this->view->assign('categories', DBUtil::getValuesForSelect('categories', 'category_id', 'category_name'));
    }

    /**
     * Obtener los resultados de una búsqueda
     */
    public function getSearch()
    {
        $this->view->addTemplate('datasearch-grid');

        $this->view->assign('isAjax', $this->_isAjax);

        $Search = new AccountSearch();

        $Search->setGlobalSearch($this->_searchGlobal);
        $Search->setSortKey($this->_sortKey);
        $Search->setSortOrder($this->_sortOrder);
        $Search->setLimitStart($this->_limitStart);
        $Search->setLimitCount($this->_limitCount);

        $Search->setTxtSearch($this->view->searchTxt);
        $Search->setCategoryId($this->view->searchCategory);
        $Search->setCustomerId($this->view->searchCustomer);
        $Search->setSearchFavorites($this->view->searchFavorites);

        $resQuery = $Search->getAccounts();

        $this->filterOn = ($this->_sortKey > 1
            || $this->view->searchCustomer
            || $this->view->searchCategory
            || $this->view->searchTxt
            || $this->view->searchFavorites
            || $Search->isSortViews());

        AccountsSearchData::$accountLink = Session::getUserPreferences()->isAccountLink();
        AccountsSearchData::$topNavbar = Session::getUserPreferences()->isTopNavbar();
        AccountsSearchData::$optionalActions = Session::getUserPreferences()->isOptionalActions();
        AccountsSearchData::$requestEnabled = Checks::mailrequestIsEnabled();
        AccountsSearchData::$wikiEnabled = Checks::wikiIsEnabled();
        AccountsSearchData::$dokuWikiEnabled = Checks::dokuWikiIsEnabled();
        AccountsSearchData::$isDemoMode = Checks::demoIsEnabled();

        if (AccountsSearchData::$wikiEnabled) {
            $this->view->assign('wikiFilter', implode('|', Config::getConfig()->getWikiFilter()));
            $this->view->assign('wikiPageUrl', Config::getConfig()->getWikiPageurl());
        }

        $Grid = $this->getGrid();
        $Grid->getData()->setData($Search->processSearchResults($resQuery));
        $Grid->updatePager();
        $Grid->setTime(round(microtime() - $this->_queryTimeStart, 5));

        $this->view->assign('data', $Grid);
    }

    /**
     * Devuelve la matriz a utilizar en la vista
     *
     * @return DataGrid
     */
    private function getGrid()
    {
        $showOptionalActions = Session::getUserPreferences()->isOptionalActions();

        $GridActionView = new DataGridAction();
        $GridActionView->setId(self::ACTION_ACC_VIEW);
        $GridActionView->setType(DataGridActionType::VIEW_ITEM);
        $GridActionView->setName(_('Detalles de Cuenta'));
        $GridActionView->setTitle(_('Detalles de Cuenta'));
        $GridActionView->setIcon($this->icons->getIconView());
        $GridActionView->setReflectionFilter('\\SP\\Account\\AccountsSearchData', 'isShowView');
        $GridActionView->setOnClickFunction('sysPassUtil.Common.accGridAction');
        $GridActionView->setOnClickArgs(self::ACTION_ACC_VIEW);
        $GridActionView->setOnClickArgs(self::ACTION_ACC_SEARCH);
        $GridActionView->setOnClickArgs('this');

        $GridActionViewPass = new DataGridAction();
        $GridActionViewPass->setId(self::ACTION_ACC_VIEW_PASS);
        $GridActionViewPass->setType(DataGridActionType::VIEW_ITEM);
        $GridActionViewPass->setName(_('Ver Clave'));
        $GridActionViewPass->setTitle(_('Ver Clave'));
        $GridActionViewPass->setIcon($this->icons->getIconViewPass());
        $GridActionViewPass->setReflectionFilter('\\SP\\Account\\AccountsSearchData', 'isShowViewPass');
        $GridActionViewPass->setOnClickFunction('sysPassUtil.Common.accGridViewPass');
        $GridActionViewPass->setOnClickArgs('this');
        $GridActionViewPass->setOnClickArgs(1);

        // Añadir la clase para usar el portapapeles
        $ClipboardIcon = $this->icons->getIconClipboard();
        $ClipboardIcon->setClass('clip-pass-button');

        $GridActionCopyPass = new DataGridAction();
        $GridActionCopyPass->setId(self::ACTION_ACC_VIEW_PASS);
        $GridActionCopyPass->setType(DataGridActionType::VIEW_ITEM);
        $GridActionCopyPass->setName(_('Copiar Clave en Portapapeles'));
        $GridActionCopyPass->setTitle(_('Copiar Clave en Portapapeles'));
        $GridActionCopyPass->setIcon($ClipboardIcon);
        $GridActionCopyPass->setReflectionFilter('\\SP\\Account\\AccountsSearchData', 'isShowCopyPass');
        $GridActionCopyPass->setOnClickFunction('sysPassUtil.Common.accGridViewPass');
        $GridActionCopyPass->setOnClickArgs('this');
        $GridActionCopyPass->setOnClickArgs(0);

        $EditIcon = $this->icons->getIconEdit();

        if (!$showOptionalActions) {
            $EditIcon->setClass('actions-optional');
        }

        $GridActionEdit = new DataGridAction();
        $GridActionEdit->setId(self::ACTION_ACC_EDIT);
        $GridActionEdit->setType(DataGridActionType::EDIT_ITEM);
        $GridActionEdit->setName(_('Editar Cuenta'));
        $GridActionEdit->setTitle(_('Editar Cuenta'));
        $GridActionEdit->setIcon($EditIcon);
        $GridActionEdit->setReflectionFilter('\\SP\\Account\\AccountsSearchData', 'isShowEdit');
        $GridActionEdit->setOnClickFunction('sysPassUtil.Common.accGridAction');
        $GridActionEdit->setOnClickArgs(self::ACTION_ACC_EDIT);
        $GridActionEdit->setOnClickArgs(self::ACTION_ACC_SEARCH);
        $GridActionEdit->setOnClickArgs('this');

        $CopyIcon = $this->icons->getIconCopy();

        if (!$showOptionalActions) {
            $CopyIcon->setClass('actions-optional');
        }

        $GridActionCopy = new DataGridAction();
        $GridActionCopy->setId(self::ACTION_ACC_COPY);
        $GridActionCopy->setType(DataGridActionType::NEW_ITEM);
        $GridActionCopy->setName(_('Copiar Cuenta'));
        $GridActionCopy->setTitle(_('Copiar Cuenta'));
        $GridActionCopy->setIcon($CopyIcon);
        $GridActionCopy->setReflectionFilter('\\SP\\Account\\AccountsSearchData', 'isShowCopy');
        $GridActionCopy->setOnClickFunction('sysPassUtil.Common.accGridAction');
        $GridActionCopy->setOnClickArgs(self::ACTION_ACC_COPY);
        $GridActionCopy->setOnClickArgs(self::ACTION_ACC_SEARCH);
        $GridActionCopy->setOnClickArgs('this');

        $DeleteIcon = $this->icons->getIconDelete();

        if (!$showOptionalActions) {
            $DeleteIcon->setClass('actions-optional');
        }

        $GridActionDel = new DataGridAction();
        $GridActionDel->setId(self::ACTION_ACC_DELETE);
        $GridActionDel->setType(DataGridActionType::DELETE_ITEM);
        $GridActionDel->setName(_('Eliminar Cuenta'));
        $GridActionDel->setTitle(_('Eliminar Cuenta'));
        $GridActionDel->setIcon($DeleteIcon);
        $GridActionDel->setReflectionFilter('\\SP\\Account\\AccountsSearchData', 'isShowDelete');
        $GridActionDel->setOnClickFunction('sysPassUtil.Common.accGridAction');
        $GridActionDel->setOnClickArgs(self::ACTION_ACC_DELETE);
        $GridActionDel->setOnClickArgs(self::ACTION_ACC_SEARCH);
        $GridActionDel->setOnClickArgs('this');

        $GridActionRequest = new DataGridAction();
        $GridActionRequest->setId(self::ACTION_ACC_REQUEST);
        $GridActionRequest->setName(_('Solicitar Modificación'));
        $GridActionRequest->setTitle(_('Solicitar Modificación'));
        $GridActionRequest->setIcon($this->icons->getIconEmail());
        $GridActionRequest->setReflectionFilter('\\SP\\Account\\AccountsSearchData', 'isShowRequest');
        $GridActionRequest->setOnClickFunction('sysPassUtil.Common.accGridAction');
        $GridActionRequest->setOnClickArgs(self::ACTION_ACC_REQUEST);
        $GridActionRequest->setOnClickArgs(self::ACTION_ACC_SEARCH);
        $GridActionRequest->setOnClickArgs('this');

        $GridActionOptional = new DataGridAction();
        $GridActionOptional->setId(self::ACTION_ACC_REQUEST);
        $GridActionOptional->setName(_('Más Acciones'));
        $GridActionOptional->setTitle(_('Más Acciones'));
        $GridActionOptional->setIcon($this->icons->getIconOptional());
        $GridActionOptional->setReflectionFilter('\\SP\\Account\\AccountsSearchData', 'isShowOptional');
        $GridActionOptional->setOnClickFunction('sysPassUtil.Common.showOptional');
        $GridActionOptional->setOnClickArgs('this');

        $GridPager = new DataGridPager();
        $GridPager->setIconPrev($this->icons->getIconNavPrev());
        $GridPager->setIconNext($this->icons->getIconNavNext());
        $GridPager->setIconFirst($this->icons->getIconNavFirst());
        $GridPager->setIconLast($this->icons->getIconNavLast());
        $GridPager->setSortKey($this->_sortKey);
        $GridPager->setSortOrder($this->_sortOrder);
        $GridPager->setLimitStart($this->_limitStart);
        $GridPager->setLimitCount($this->_limitCount);
        $GridPager->setOnClickFunction('sysPassUtil.Common.searchSort');
        $GridPager->setOnClickArgs($this->_sortKey);
        $GridPager->setOnClickArgs($this->_sortOrder);
        $GridPager->setFilterOn($this->filterOn);

        $Grid = new DataGrid();
        $Grid->setId('gridSearch');
        $Grid->setDataHeaderTemplate('datasearch-header');
        $Grid->setDataRowTemplate('datasearch-rows');
        $Grid->setDataPagerTemplate('datagrid-nav-full');
        $Grid->setHeader($this->getHeaderSort());
        $Grid->setDataActions($GridActionView);
        $Grid->setDataActions($GridActionViewPass);
        $Grid->setDataActions($GridActionCopyPass);
        $Grid->setDataActions($GridActionOptional);
        $Grid->setDataActions($GridActionEdit);
        $Grid->setDataActions($GridActionCopy);
        $Grid->setDataActions($GridActionDel);
        $Grid->setDataActions($GridActionRequest);
        $Grid->setPager($GridPager);
        $Grid->setData(new DataGridData());

        return $Grid;
    }

    /**
     * Devolver la cabecera con los campos de ordenación
     *
     * @return DataGridHeaderSort
     */
    private function getHeaderSort()
    {
        $GridSortCustomer = new DataGridSort();
        $GridSortCustomer->setName(_('Cliente'));
        $GridSortCustomer->setTitle(_('Ordenar por Cliente'));
        $GridSortCustomer->setSortKey(AccountSearch::SORT_CUSTOMER);
        $GridSortCustomer->setIconUp($this->icons->getIconUp());
        $GridSortCustomer->setIconDown($this->icons->getIconDown());

        $GridSortName = new DataGridSort();
        $GridSortName->setName(_('Nombre'));
        $GridSortName->setTitle(_('Ordenar por Nombre'));
        $GridSortName->setSortKey(AccountSearch::SORT_NAME);
        $GridSortName->setIconUp($this->icons->getIconUp());
        $GridSortName->setIconDown($this->icons->getIconDown());

        $GridSortCategory = new DataGridSort();
        $GridSortCategory->setName(_('Categoría'));
        $GridSortCategory->setTitle(_('Ordenar por Categoría'));
        $GridSortCategory->setSortKey(AccountSearch::SORT_CATEGORY);
        $GridSortCategory->setIconUp($this->icons->getIconUp());
        $GridSortCategory->setIconDown($this->icons->getIconDown());

        $GridSortLogin = new DataGridSort();
        $GridSortLogin->setName(_('Usuario'));
        $GridSortLogin->setTitle(_('Ordenar por Usuario'));
        $GridSortLogin->setSortKey(AccountSearch::SORT_LOGIN);
        $GridSortLogin->setIconUp($this->icons->getIconUp());
        $GridSortLogin->setIconDown($this->icons->getIconDown());

        $GridSortUrl = new DataGridSort();
        $GridSortUrl->setName(_('URL / IP'));
        $GridSortUrl->setTitle(_('Ordenar por URL / IP'));
        $GridSortUrl->setSortKey(AccountSearch::SORT_URL);
        $GridSortUrl->setIconUp($this->icons->getIconUp());
        $GridSortUrl->setIconDown($this->icons->getIconDown());

        $GridHeaderSort = new DataGridHeaderSort();
        $GridHeaderSort->addSortField($GridSortCustomer);
        $GridHeaderSort->addSortField($GridSortName);
        $GridHeaderSort->addSortField($GridSortCategory);
        $GridHeaderSort->addSortField($GridSortLogin);
        $GridHeaderSort->addSortField($GridSortUrl);

        return $GridHeaderSort;
    }
}