<?php
/* BankImport Module for Dolibarr */

include_once DOL_DOCUMENT_ROOT . '/core/modules/DolibarrModules.class.php';

require_once __DIR__ . '/BankImportHelper.php';

class modBankImport extends DolibarrModules
{
    /**
     * Constructor
     */
    function __construct($db)
    {
        global $langs, $conf;
        $this->db = $db;

        // Load translations
        $langs->load("bankimport@bankimport");

        $this->version = '0.0.10';

        // Unique ID (custom modules > 100000)
        $this->numero = 104001;

        $this->rights_class = 'bankimport';

        // Where the module shows up in Setup
        $this->family = "financial";
        $this->name = "BankImport";
        // Use translation instead of hardcoded German text
        $this->description = $langs->trans("BANKIMPORT_Setup_Description");
        $this->const_name = 'MAIN_MODULE_BANKIMPORT';
        $this->license = 'MIT';
        $this->special = 0;
        $this->picto = 'bank-import-logo@bankimport';
        $this->editor_name = 'Tilo Thiele';
        $this->editor_url = 'mailto:tilo.thiele@hamburg.de';

        // Default module options
        $this->module_parts = array();
        $this->dirs = array();
        $this->config_page_url = array('setup.php@bankimport');
        $this->depends = array();
        $this->requiredby = array();
        $this->phpmin = array(7, 4);
        $this->langfiles = array("bankimport@bankimport");

        // --- Permissions definition ---
        $r = 0;

        $this->rights[$r][0] = $this->numero + $r;
        // Use translation for permission description
        $this->rights[$r][1] = $langs->trans('BANKIMPORT_Permission_Import');
        $this->rights[$r][3] = 1;
        $this->rights[$r][4] = 'import';
        
        $r++;

        $this->rights[$r][0] = $this->numero + $r;
        // Use translation for permission description
        $this->rights[$r][1] = $langs->trans('BANKIMPORT_Permission_Reconcile');
        $this->rights[$r][3] = 1;
        $this->rights[$r][4] = 'reconcile';

        // --- Menu definition ---
        $r = 0;
        
        $this->menu[$r++] = array(
            'fk_menu'   => 'fk_mainmenu=bank',
            'type'      => 'left',
            // Use translation for menu title
            'titre'     => $langs->trans('BANKIMPORT_Menu_Title'),
            'mainmenu'  => 'bank',
            'leftmenu'  => 'bankimport_main',
            'url'       => '/custom/bankimport/import.php',
            'langs'     => 'bankimport@bankimport',
            'position'  => 100,
            'enabled'   => '1',
            'perms'     => '1',
            'target'    => '',
            'user'      => 0
        );

        // Sub-menu 1: Reconcile Invoices
        $this->menu[$r++] = array(
            'fk_menu'   => 'fk_mainmenu=bank,fk_leftmenu=bankimport_main',
            'type'      => 'left',
            'titre'     => $langs->trans('BANKIMPORT_Menu_Reconcile_Invoices'),
            'mainmenu'  => 'bank',
            'leftmenu'  => 'bankimport_reconcile_invoices',
            'url'       => '/custom/bankimport/reconcile_invoice.php',
            'langs'     => 'bankimport@bankimport',
            'position'  => 101,
            'enabled'   => '1',
            'perms'     => '1',
            'target'    => '',
            'user'      => 0
        );

        // Sub-menu 2: Reconcile Paiements
        $this->menu[$r++] = array(
            'fk_menu'   => 'fk_mainmenu=bank,fk_leftmenu=bankimport_main',
            'type'      => 'left',
            'titre'     => $langs->trans('BANKIMPORT_Menu_Reconcile_Payments'),
            'mainmenu'  => 'bank',
            'leftmenu'  => 'bankimport_reconcile_payments',
            'url'       => '/custom/bankimport/reconcile_paiement.php',
            'langs'     => 'bankimport@bankimport',
            'position'  => 102,
            'enabled'   => '1',
            'perms'     => '1',
            'target'    => '',
            'user'      => 0
        );
    }
}