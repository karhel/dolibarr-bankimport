<?php
/* Copyright (C) 2024 Tilo Thiele <tilo.thiele@hamburg.de>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';

/**
 * BankImport class
 */
class BankImport extends CommonObject
{
    /**
     * @var DoliDB Database handler.
     */
    public $db;

    /**
     * @var string Error code (or message)
     */
    public $error = '';

    /**
     * @var string[] Error codes (or messages)
     */
    public $errors = array();

    /**
     * @var int Bank account ID
     */
    public $accountid;

    /**
     * @var string File encoding
     */
    public $encoding;

    /**
     * @var string CSV format type
     */
    public $format;

    /**
     * @var string CSV separator
     */
    public $separator = ';';

    /**
     * @var array CSV field mapping for camt.052 format
     */
    public $fieldMappingCamt = array(
        'account' => 0,
        'booking_date' => 1,
        'value_date' => 2,
        'booking_text' => 3,
        'payment_purpose' => 4,
        'creditor_id' => 5,
        'mandate_reference' => 6,
        'collector_reference' => 8,
        'counterparty_name' => 11,
        'counterparty_iban' => 12,
        'counterparty_bic' => 13,
        'amount' => 14,
        'currency' => 15,
        'info' => 16
    );

    /**
     * @var array CSV field mapping for simple format
     * Format: Date, Date de valeur, Débit, Crédit, Libellé, Solde
     */
    public $fieldMappingSimple = array(
        'date' => 0,              // Date
        'value_date' => 1,        // Date de valeur
        'debit' => 2,             // Débit
        'credit' => 3,            // Crédit
        'label' => 4,             // Libellé
        'balance' => 5            // Solde
    );

    /**
     * Constructor
     *
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        $this->db = $db;
        $this->format = 'simple'; // Default format
    }

    /**
     * Set account ID
     *
     * @param int $accountid Bank account ID
     * @return void
     */
    public function setAccountId($accountid)
    {
        $this->accountid = (int) $accountid;
    }

    /**
     * Set encoding
     *
     * @param string $encoding File encoding
     * @return void
     */
    public function setEncoding($encoding)
    {
        $this->encoding = $encoding;
    }

    /**
     * Set format
     *
     * @param string $format CSV format (simple or camt052)
     * @return void
     */
    public function setFormat($format)
    {
        $this->format = $format;
    }

    /**
     * Set CSV separator
     *
     * @param string $separator CSV separator
     * @return void
     */
    public function setSeparator($separator)
    {
        $this->separator = $separator;
    }

    /**
     * Validate uploaded file
     *
     * @param array $file $_FILES array element
     * @return bool True if valid, false otherwise
     */
    public function validateFile($file)
    {
        if (!isset($file['tmp_name']) || empty($file['tmp_name'])) {
            $this->error = 'No file uploaded';
            return false;
        }

        if (!is_uploaded_file($file['tmp_name'])) {
            $this->error = 'Invalid file upload';
            return false;
        }

        if ($file['size'] > 10 * 1024 * 1024) { // 10MB limit
            $this->error = 'File too large (max 10MB)';
            return false;
        }

        $allowedTypes = array('text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel');
        if (!in_array($file['type'], $allowedTypes) && !preg_match('/\.csv$/i', $file['name'])) {
            $this->error = 'Invalid file type (CSV required)';
            return false;
        }

        return true;
    }

    /**
     * Detect CSV format from header
     *
     * @param string $filename File path
     * @return string Format type (simple or camt052)
     */
    public function detectFormat($filename)
    {
        $handle = fopen($filename, 'r');
        if (!$handle) {
            return 'simple';
        }

        $header = fgetcsv($handle, 0, $this->separator);
        fclose($handle);

        if (!$header) {
            return 'simple';
        }

        // Convert encoding if needed
        $header = $this->convertEncoding($header);

        // Check for simple format headers
        $simpleHeaders = array('Date', 'Date de valeur', 'Débit', 'Crédit', 'Libellé', 'Solde');
        $matches = 0;
        foreach ($simpleHeaders as $expectedHeader) {
            foreach ($header as $col) {
                if (stripos($col, $expectedHeader) !== false || stripos($expectedHeader, $col) !== false) {
                    $matches++;
                    break;
                }
            }
        }

        // If we found most of the simple format headers, use simple format
        if ($matches >= 4) {
            return 'simple';
        }

        // Check for camt.052 format (has more columns)
        if (count($header) >= 15) {
            return 'camt052';
        }

        return 'simple';
    }

    /**
     * Process CSV file
     *
     * @param string $filename File path
     * @return array Array with success count and errors
     */
    public function processFile($filename)
    {
        $result = array(
            'success' => 0,
            'errors' => array(),
            'skipped' => 0
        );

        // Validate account ID is set
        if (empty($this->accountid) || $this->accountid <= 0) {
            $this->error = 'No valid bank account selected';
            $result['errors'][] = 'No valid bank account selected';
            return $result;
        }

        // Auto-detect format if not set
        if (empty($this->format) || $this->format == 'auto') {
            $this->format = $this->detectFormat($filename);
        }

        $handle = fopen($filename, 'r');
        if (!$handle) {
            $this->error = 'Could not open file';
            return $result;
        }

        $row = 0;
        while (($data = fgetcsv($handle, 0, $this->separator)) !== FALSE) {
            $row++;
            if ($row == 1) continue; // Skip header

            // Convert encoding if needed
            $data = $this->convertEncoding($data);

            // Validate data
            if (!$this->validateRow($data, $row)) {
                $result['errors'][] = "Row $row: " . $this->error;
                continue;
            }

            // Process row based on format
            if ($this->format == 'simple') {
                $importResult = $this->processRowSimple($data, $row);
            } else {
                $importResult = $this->processRowCamt($data, $row);
            }

            if ($importResult === true) {
                $result['success']++;
            } elseif ($importResult === 'skipped') {
                $result['skipped']++;
            } else {
                $result['errors'][] = "Row $row: " . $importResult;
            }
        }

        fclose($handle);
        return $result;
    }

    /**
     * Convert encoding of data array
     *
     * @param array $data Data array
     * @return array Converted data array
     */
    private function convertEncoding($data)
    {
        if ($this->encoding && strtoupper($this->encoding) !== 'UTF-8') {
            foreach ($data as &$field) {
                $field = iconv($this->encoding, "UTF-8//TRANSLIT", $field);
            }
        }
        return $data;
    }

    /**
     * Validate CSV row data
     *
     * @param array $data Row data
     * @param int $row Row number
     * @return bool True if valid, false otherwise
     */
    private function validateRow($data, $row)
    {
        if ($this->format == 'simple') {
            // Simple format needs at least 6 columns
            if (count($data) < 6) {
                $this->error = 'Insufficient columns in CSV (expected 6: Date, Date de valeur, Débit, Crédit, Libellé, Solde)';
                return false;
            }

            // Validate required fields
            if (empty($data[$this->fieldMappingSimple['date']])) {
                $this->error = 'Missing date';
                return false;
            }

            // At least one of debit or credit must have a value
            if (empty($data[$this->fieldMappingSimple['debit']]) && empty($data[$this->fieldMappingSimple['credit']])) {
                $this->error = 'Missing amount (both debit and credit are empty)';
                return false;
            }
        } else {
            // camt.052 format needs at least 15 columns
            if (count($data) < 15) {
                $this->error = 'Insufficient columns in CSV';
                return false;
            }

            if (empty($data[$this->fieldMappingCamt['booking_date']])) {
                $this->error = 'Missing booking date';
                return false;
            }

            if (empty($data[$this->fieldMappingCamt['amount']])) {
                $this->error = 'Missing amount';
                return false;
            }
        }

        return true;
    }

    /**
     * Process single CSV row (simple format)
     *
     * @param array $data Row data
     * @param int $row Row number
     * @return bool|string True on success, 'skipped' if already imported, error message on failure
     */
    private function processRowSimple($data, $row)
    {
        global $user;

        $mapping = $this->fieldMappingSimple;

        // Extract data
        $dateo = $this->parseDateSimple($data[$mapping['date']]);
        $datev = !empty($data[$mapping['value_date']]) ? $this->parseDateSimple($data[$mapping['value_date']]) : $dateo;
        $label = $this->limitString($data[$mapping['label']]);
        
        // Calculate amount (debit is negative, credit is positive)
        $debit = $this->parseAmount($data[$mapping['debit']]);
        $credit = $this->parseAmount($data[$mapping['credit']]);
        
        if ($debit > 0) {
            $amount = -$debit; // Debit is negative
        } else {
            $amount = $credit; // Credit is positive
        }

        $oper = 'IMPORT'; //($amount < 0) ? 'PRE' : 'VIR'; // PRE for debit, VIR for credit
        $ref = '';
        $categorie = null;
        $transaction_id = null;
        $bank_other = '';
        $iban_other = '';
        $owner_other = '';

        // Generate import key
        $import_key = $this->generateImportKey($transaction_id, $iban_other, $owner_other, $amount, $label, $ref);

        // Check if already imported
        if ($this->isAlreadyImported($import_key)) {
            return 'skipped';
        }

        // Prepare notes
        $note = '';
        /*if (!empty($data[$mapping['balance']])) {
            $balance = $this->parseAmount($data[$mapping['balance']]);
            $note = 'Solde: ' . number_format($balance, 2, ',', ' ');
        }*/

        // Begin transaction
        $this->db->begin();

        try {
            $account = new Account($this->db);
            $account->fetch($this->accountid);

            $bankline_id = $account->addline(
                $dateo,
                $oper,
                $label,
                $amount,
                $ref,
                $categorie,
                $user,
                $owner_other,
                $bank_other,
                $iban_other,
                $datev,
                null, // num_releve
                null, // amount_main_currency
                $note
            );

            if ($bankline_id > 0) {
                // Update import key
                $this->updateImportKey($bankline_id, $import_key);
                $this->db->commit();
                return true;
            } else {
                $this->db->rollback();
                return $account->error;
            }
        } catch (Exception $e) {
            $this->db->rollback();
            return $e->getMessage();
        }
    }

    /**
     * Process single CSV row (camt.052 format)
     *
     * @param array $data Row data
     * @param int $row Row number
     * @return bool|string True on success, 'skipped' if already imported, error message on failure
     */
    private function processRowCamt($data, $row)
    {
        global $user;

        $mapping = $this->fieldMappingCamt;

        // Extract data
        $dateo = $this->parseDate($data[$mapping['booking_date']]);
        $datev = $this->parseDate($data[$mapping['value_date']]);
        $label = $this->limitString($data[$mapping['payment_purpose']]);
        $amount = price2num($data[$mapping['amount']]);
        $oper = 'VIR';
        $ref = trim($data[$mapping['mandate_reference']]);
        $categorie = null;
        $transaction_id = null;
        $bank_other = $data[$mapping['counterparty_bic']];
        $iban_other = $data[$mapping['counterparty_iban']];
        $owner_other = $data[$mapping['counterparty_name']];

        // Generate import key
        $import_key = $this->generateImportKey($transaction_id, $iban_other, $owner_other, $amount, $label, $ref);

        // Check if already imported
        if ($this->isAlreadyImported($import_key)) {
            return 'skipped';
        }

        // Prepare notes
        $note = $this->buildNoteCamt($data);

        // Begin transaction
        $this->db->begin();

        try {
            $account = new Account($this->db);
            $account->fetch($this->accountid);

            $bankline_id = $account->addline(
                $dateo,
                $oper,
                $label,
                $amount,
                $ref,
                $categorie,
                $user,
                $owner_other,
                $bank_other,
                $iban_other,
                $datev,
                null, // num_releve
                null, // amount_main_currency
                $note
            );

            if ($bankline_id > 0) {
                // Update import key
                $this->updateImportKey($bankline_id, $import_key);
                $this->db->commit();
                return true;
            } else {
                $this->db->rollback();
                return $account->error;
            }
        } catch (Exception $e) {
            $this->db->rollback();
            return $e->getMessage();
        }
    }

    /**
     * Parse date from DD.MM.YY format (camt.052)
     *
     * @param string $dateString Date string
     * @return int Timestamp
     */
    private function parseDate($dateString)
    {
        $dd = substr($dateString, 0, 2);
        $mm = substr($dateString, 3, 2);
        $yyyy = substr($dateString, 6, 2);
        if (!empty($yyyy)) {
            $yyyy = '20' . $yyyy;
        }
        return dol_mktime(0, 0, 0, $mm, $dd, $yyyy);
    }

    /**
     * Parse date from multiple formats (simple format)
     * Supports: DD/MM/YYYY, DD-MM-YYYY, DD.MM.YYYY, YYYY-MM-DD
     *
     * @param string $dateString Date string
     * @return int Timestamp
     */
    private function parseDateSimple($dateString)
    {
        $dateString = trim($dateString);
        
        // Try different formats
        // Format: DD/MM/YYYY or DD-MM-YYYY or DD.MM.YYYY
        if (preg_match('/^(\d{1,2})[\/\-\.](\d{1,2})[\/\-\.](\d{4})$/', $dateString, $matches)) {
            $dd = $matches[1];
            $mm = $matches[2];
            $yyyy = $matches[3];
            return dol_mktime(0, 0, 0, $mm, $dd, $yyyy);
        }
        
        // Format: YYYY-MM-DD
        if (preg_match('/^(\d{4})\-(\d{1,2})\-(\d{1,2})$/', $dateString, $matches)) {
            $yyyy = $matches[1];
            $mm = $matches[2];
            $dd = $matches[3];
            return dol_mktime(0, 0, 0, $mm, $dd, $yyyy);
        }
        
        // Format: DD/MM/YY
        if (preg_match('/^(\d{1,2})[\/\-\.](\d{1,2})[\/\-\.](\d{2})$/', $dateString, $matches)) {
            $dd = $matches[1];
            $mm = $matches[2];
            $yyyy = '20' . $matches[3];
            return dol_mktime(0, 0, 0, $mm, $dd, $yyyy);
        }
        
        // Fallback: use current date
        return dol_now();
    }

    /**
     * Parse amount from string
     * Handles various formats: 1234.56, 1 234,56, 1.234,56, etc.
     *
     * @param string $amountString Amount string
     * @return float Amount
     */
    private function parseAmount($amountString)
    {
        if (empty($amountString)) {
            return 0;
        }
        
        $amountString = trim($amountString);
        
        // Remove spaces
        $amountString = str_replace(' ', '', $amountString);
        
        // Replace comma with dot for decimal separator
        // But first, remove dots if they're used as thousand separators
        if (preg_match('/\d\.\d{3}/', $amountString)) {
            // Dots are thousand separators, remove them
            $amountString = str_replace('.', '', $amountString);
        }
        
        // Now replace comma with dot for decimal
        $amountString = str_replace(',', '.', $amountString);
        
        return (float) $amountString;
    }

    /**
     * Limit string length
     *
     * @param string|null $text Text to limit
     * @param int $length Maximum length
     * @param bool $fixed Fixed length
     * @return string Limited string
     */
    private function limitString($text, $length = 255, $fixed = false)
    {
        if ($text === null) {
            return $fixed ? str_repeat(' ', $length) : '';
        }
        $limited = substr($text, 0, $length);
        return $fixed ? str_pad($limited, $length) : $limited;
    }

    /**
     * Generate import key
     *
     * @param string|null $transaction_id Transaction ID
     * @param string $iban_other Counterparty IBAN
     * @param string $owner_other Counterparty name
     * @param float $amount Amount
     * @param string $label Label
     * @param string $ref Reference
     * @return string Import key
     */
    private function generateImportKey($transaction_id, $iban_other, $owner_other, $amount, $label, $ref)
    {
        if (!empty($transaction_id)) {
            return trim($transaction_id);
        }

        $key = implode('|', array(
            trim($iban_other),
            trim($owner_other),
            number_format($amount, 2, '.', ''),
            trim($label),
            trim($ref)
        ));
        return substr(sha1($key), 0, 14);
    }

    /**
     * Check if transaction is already imported
     *
     * @param string $import_key Import key
     * @return bool True if already imported
     */
    private function isAlreadyImported($import_key)
    {
        $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "bank WHERE import_key = '" . $this->db->escape($import_key) . "'";
        $resql = $this->db->query($sql);
        if ($resql) {
            return $this->db->num_rows($resql) > 0;
        }
        return false;
    }

    /**
     * Update import key for bank line
     *
     * @param int $bankline_id Bank line ID
     * @param string $import_key Import key
     * @return bool Success
     */
    private function updateImportKey($bankline_id, $import_key)
    {
        $sql = "UPDATE " . MAIN_DB_PREFIX . "bank SET import_key = '" . $this->db->escape($import_key) . "' WHERE rowid = " . ((int) $bankline_id);
        return $this->db->query($sql);
    }

    /**
     * Build note from CSV data (camt.052 format)
     *
     * @param array $data CSV data
     * @return string Note
     */
    private function buildNoteCamt($data)
    {
        $mapping = $this->fieldMappingCamt;
        $note = '';
        $sep = '';

        if (!empty($data[$mapping['collector_reference']])) {
            $note .= $sep . 'Sammlerreferenz=' . $data[$mapping['collector_reference']];
            $sep = ' ';
        }

        if (!empty($data[$mapping['creditor_id']])) {
            $note .= $sep . 'GlaeubigerId=' . $data[$mapping['creditor_id']];
            $sep = ' ';
        }

        return $note;
    }
}