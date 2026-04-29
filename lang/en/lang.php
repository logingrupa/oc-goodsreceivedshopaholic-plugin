<?php

return [
    'plugin' => [
        'name'        => 'Goods Received Notes',
        'description' => 'Distributor goods-received note import — parses HTM delivery receipts and increments offer stock.',
    ],
    'settings' => [
        'label'       => 'Goods Received',
        'description' => 'Configure stock-import behavior, active-flag automation, and per-site toggles.',
    ],
    'field' => [
        'enabled'                       => 'Enable goods-received import',
        'enabled_comment'               => 'Master toggle for HTM invoice parsing and stock writes on this site.',
        'auto_deactivate_on_zero'       => 'Auto-deactivate offers at zero stock',
        'auto_deactivate_on_zero_comment' => 'When an offer quantity reaches zero, set offer.active=false.',
        'auto_activate_on_stock'        => 'Auto-activate offers on inbound stock',
        'auto_activate_on_stock_comment' => 'When inbound stock arrives for an inactive offer, set offer.active=true.',
        'allow_initial_reset'           => 'Allow initial-reset checkbox',
        'allow_initial_reset_comment'   => 'Operators can tick a one-shot baseline reset on import preview: zero all offer quantities and deactivate all products/offers before applying.',
    ],
    'menu' => [
        'goods_received'      => 'Goods Received',
        'goods_received_desc' => 'Upload distributor delivery receipts and apply to stock.',
    ],
];
