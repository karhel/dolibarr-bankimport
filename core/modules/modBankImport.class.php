<?php
/* BankImport minimal Module for Dolibarr */

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

        $this->version = BankImportHelper::getEnv('VERSION', '0.0.10');

        // Unique ID (custom modules > 100000)
        $this->numero = 104001;

        $this->rights_class = 'bankimport';

        // Where the module shows up in Setup
        $this->family = "financial";
        $this->name = "BankImport";
        $this->description = "Import von Kontoauszügen";
        $this->const_name = 'MAIN_MODULE_BANKIMPORT';
        $this->license = 'MIT';
        $this->special = 0;
        $this->picto = 'bank-import-logo@bankimport';
        $this->editor_name = 'Tilo Thiele';
        $this->editor_url = 'mailto:tilo.thiele@hamburg.de';

        // Default module options
        $this->module_parts = array();
        $this->dirs = array();
        //$this->config_page_url = array('setup.php@bankimport');
        $this->config_page_url = array();
        $this->depends = array();
        $this->requiredby = array();
        $this->phpmin = array(7, 4);
        $this->langfiles = array("bankimport@bankimport");

        // --- Permissions definition ---
        $r = 0;
        $this->rights[$r][0] = $this->numero + $r;
        $this->rights[$r][1] = 'Bankauszüge importieren';
        $this->rights[$r][2] = 'w';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'import';
        $r++;

        // --- Menu definition ---
        $r = 0;
        $this->menu[$r++] = array(
            'fk_menu'   => 'fk_mainmenu=bank',
            'type'      => 'left',
            'titre'     => 'Kontoauszüge importieren',
            'mainmenu'  => 'bank',
            'leftmenu'  => 'bankimport',
            'url'       => '/custom/bankimport/import.php',
            'langs'     => 'bankimport@bankimport',
            'position'  => 100,
            'enabled'   => '1',
            'perms'     => '$user->hasRight("banque", "modifier")',
            'target'    => '',
            'user'      => 0
        );
    }
}
