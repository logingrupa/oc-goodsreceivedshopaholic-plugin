<?php

declare(strict_types=1);

namespace Logingrupa\GoodsReceivedShopaholic\Controllers;

use Backend\Classes\Controller;
use BackendMenu;

/**
 * Backend controller for Goods Received Invoices (UI-01 / UI-05 / UI-06 / UI-07
 * — D-01..D-04).
 *
 * Thin per D-03: implements ListController + FormController + RelationController
 * behaviors and delegates ALL business logic to Phase 3 orchestrators
 * (ParseAndPersistOrchestrator + ApplyOrchestrator). Class-level loose permission
 * gate via $requiredPermissions enforces "operators can use this controller at
 * all" (D-02); fine-grained per-action gates ship in plans 04-04 (onUpload),
 * 04-05 (onApply), 04-06 (onOverrideConfirm + onInitialResetConfirm).
 *
 * Registered under Settings menu (NOT main nav per locked decision D6 / D-04).
 * The Settings menu wiring lives in Plugin::registerSettings — TWO entries:
 * one for the Settings model (existing) and one for this controller (UI-05).
 */
final class Invoices extends Controller
{
    /** @var list<string> */
    public $implement = [
        'Backend.Behaviors.ListController',
        'Backend.Behaviors.FormController',
        'Backend.Behaviors.RelationController',
    ];

    /** @var string */
    public $listConfig = 'config_list.yaml';

    /** @var string */
    public $formConfig = 'config_form.yaml';

    /** @var string */
    public $relationConfig = 'config_relation.yaml';

    /** @var list<string> */
    public $requiredPermissions = ['logingrupa.goodsreceived.upload_invoices'];

    public function __construct()
    {
        parent::__construct();
        BackendMenu::setContext('October.System', 'system', 'settings');
    }
}
