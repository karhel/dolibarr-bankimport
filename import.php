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


// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
// Try main.inc.php using relative path
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

require_once __DIR__ . '/core/class/BankImport.class.php';

// Security check - check for bankimport rights
if (!$user->hasRight('bankimport', 'import')) {
    accessforbidden();
}

$langs->load("bankimport@bankimport");

llxHeader('', $langs->trans("BANKIMPORT_Title"));

print load_fiche_titre($langs->trans("BANKIMPORT_Title"));

// Get parameters
$accountid = GETPOST('accountid', 'int');
$encoding = GETPOST('encoding', 'alpha'); // UTF-8 ou ISO-8859-1
$format = GETPOST('format', 'alpha'); // simple or camt052
$separator = GETPOST('separator', 'alpha'); // CSV separator
$action = GETPOST('action', 'alpha');

// Token validation is handled by Dolibarr automatically

// Initialize BankImport object
$bankImport = new BankImport($db);

// Handle form submission
if ($action == 'upload') {
    // Validate required fields first
    $errors = array();

    // Check if bank account is selected
    if (empty($accountid) || $accountid == 0 || $accountid == '0') {
        $errors[] = $langs->trans("BANKIMPORT_Choose_account");
    } else {
        // Verify that the bank account exists
        $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "bank_account WHERE rowid = " . ((int) $accountid);
        $resql = $db->query($sql);
        if (!$resql || $db->num_rows($resql) == 0) {
            $errors[] = $langs->trans("BANKIMPORT_Invalid_account");
        }
    }

    // Check if file is uploaded
    if (empty($_FILES['statement']['tmp_name'])) {
        $errors[] = $langs->trans("BANKIMPORT_Choose_file");
    }

    // Display errors if any
    if (!empty($errors)) {
        foreach ($errors as $error) {
            setEventMessages($error, null, 'errors');
        }
    } else {
        // All validations passed, proceed with import
        $bankImport->setAccountId($accountid);
        $bankImport->setEncoding($encoding);
        $bankImport->setFormat($format);
        $bankImport->setSeparator($separator ? $separator : ';');

        // Validate file
        if (!$bankImport->validateFile($_FILES['statement'])) {
            setEventMessages($bankImport->error, null, 'errors');
        } else {
            // Process file
            $result = $bankImport->processFile($_FILES['statement']['tmp_name']);

            // Display results
            if ($result['success'] > 0) {
                setEventMessages($langs->trans("BANKIMPORT_Success_imported", $result['success']), null, 'mesgs');
            }

            if ($result['skipped'] > 0) {
                setEventMessages($langs->trans("BANKIMPORT_Skipped_imported", $result['skipped']), null, 'warnings');
            }

            if (!empty($result['errors'])) {
                foreach ($result['errors'] as $error) {
                    setEventMessages($error, null, 'errors');
                }
            }
        }
    }
}

// Display form
print '<form action="'.$_SERVER["PHP_SELF"].'" method="post" enctype="multipart/form-data">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="upload">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td colspan="2">'.$langs->trans("BANKIMPORT_Import_Form").'</td>';
print '</tr>';

// Bank account selection
print '<tr class="oddeven">';
print '<td class="fieldrequired">'.$langs->trans("BANKIMPORT_Bank_account").'</td>';
print '<td>';
$form = new Form($db);
print $form->select_comptes($accountid, 'accountid', 0, '', 1, 0, 'all');
print '<span class="fieldrequired" style="color: red;">*</span>';
print '</td>';
print '</tr>';

// File upload
print '<tr class="oddeven">';
print '<td class="fieldrequired">'.$langs->trans("BANKIMPORT_File_label").'</td>';
print '<td>';
print '<input type="file" name="statement" accept=".csv,text/csv,text/plain" required>';
print '</td>';
print '</tr>';

// Format selection
print '<tr class="oddeven">';
print '<td>'.$langs->trans("BANKIMPORT_Format").'</td>';
print '<td>';
print '<select name="format" id="formatSelect">';
$formats = array(
    'auto' => $langs->trans("BANKIMPORT_Format_Auto"),
    'simple' => $langs->trans("BANKIMPORT_Format_Simple"),
    'camt052' => $langs->trans("BANKIMPORT_Format_Camt052")
);
foreach ($formats as $key => $label) {
    $selected = ($format == $key || ($format == '' && $key == 'auto')) ? 'selected' : '';
    print '<option value="'.$key.'" '.$selected.'>'.$label.'</option>';
}
print '</select>';
print ' <span class="opacitymedium">'.$langs->trans("BANKIMPORT_Format_Help").'</span>';
print '</td>';
print '</tr>';

// CSV Separator selection
print '<tr class="oddeven">';
print '<td>'.$langs->trans("BANKIMPORT_Separator").'</td>';
print '<td>';
print '<select name="separator">';
$separators = array(
    ';' => $langs->trans("BANKIMPORT_Separator_Semicolon"),
    ',' => $langs->trans("BANKIMPORT_Separator_Comma"),
    "\t" => $langs->trans("BANKIMPORT_Separator_Tab")
);
foreach ($separators as $key => $label) {
    $selected = ($separator == $key || ($separator == '' && $key == ';')) ? 'selected' : '';
    print '<option value="'.htmlspecialchars($key).'" '.$selected.'>'.$label.'</option>';
}
print '</select>';
print '</td>';
print '</tr>';

// Encoding selection
print '<tr class="oddeven">';
print '<td>'.$langs->trans("BANKIMPORT_Encoding").'</td>';
print '<td>';
print '<select name="encoding">';
$encodings = array('UTF-8' => 'UTF-8', 'ISO-8859-1' => 'ISO-8859-1');
foreach ($encodings as $key => $label) {
    $selected = ($encoding == $key || ($encoding == '' && $key == 'UTF-8')) ? 'selected' : '';
    print '<option value="'.$key.'" '.$selected.'>'.$label.'</option>';
}
print '</select>';
print '</td>';
print '</tr>';

// Submit button
print '<tr class="oddeven">';
print '<td colspan="2" class="center">';
print '<input type="submit" class="button" id="submitButton" value="'.$langs->trans("BANKIMPORT_Importieren_label").'" disabled>';
print '</td>';
print '</tr>';

print '</table>';
print '</form>';

// JavaScript validation
print '<script type="text/javascript">
document.addEventListener("DOMContentLoaded", function() {
    var form = document.querySelector("form");
    var accountSelect = document.querySelector("select[name=\'accountid\']");
    var fileInput = document.querySelector("input[name=\'statement\']");
    var submitButton = document.getElementById("submitButton");

    // Disable submit button initially if no account selected
    function updateSubmitButton() {
        var hasAccount = accountSelect.value && accountSelect.value != "0";
        var hasFile = fileInput.files && fileInput.files.length > 0;
        submitButton.disabled = !hasAccount || !hasFile;
    }

    // Update submit button state when selections change
    accountSelect.addEventListener("change", updateSubmitButton);
    fileInput.addEventListener("change", updateSubmitButton);

    // Initial state
    updateSubmitButton();

    form.addEventListener("submit", function(e) {
        var isValid = true;
        var errorMessages = [];

        // Check if bank account is selected
        if (!accountSelect.value || accountSelect.value == "0") {
            errorMessages.push("' . $langs->trans("BANKIMPORT_Choose_account") . '");
            accountSelect.focus();
            isValid = false;
        }

        // Check if file is selected
        if (!fileInput.files || fileInput.files.length === 0) {
            errorMessages.push("' . $langs->trans("BANKIMPORT_Choose_file") . '");
            if (isValid) fileInput.focus();
            isValid = false;
        }

        if (!isValid) {
            e.preventDefault();
            alert(errorMessages.join("\\n"));
        }
    });
});
</script>';

// Display help information
print '<br>';
print '<div class="info">';
print '<strong>'.$langs->trans("BANKIMPORT_Help_Title").'</strong><br>';
print $langs->trans("BANKIMPORT_Help_Description").'<br><br>';

print '<strong>'.$langs->trans("BANKIMPORT_Help_Format").'</strong><br>';
print $langs->trans("BANKIMPORT_Help_Format_Details").'<br><br>';

print '<strong>'.$langs->trans("BANKIMPORT_Format_Simple_Title").'</strong><br>';
print $langs->trans("BANKIMPORT_Format_Simple_Description").'<br>';
print '<code>Date; Date de valeur; Débit; Crédit; Libellé; Solde</code><br><br>';

print '<strong>'.$langs->trans("BANKIMPORT_Format_Camt052_Title").'</strong><br>';
print $langs->trans("BANKIMPORT_Format_Camt052_Description");
print '</div>';

llxFooter();
$db->close();