<?php
/* Copyright (C) 2024 Tilo Thiele <tilo.thiele@hamburg.de>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
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
require_once DOL_DOCUMENT_ROOT.'/core/lib/bank.lib.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/fourn/class/paiementfourn.class.php';
require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';

// Security check
if (!$user->hasRight('bankimport', 'import')) {
    accessforbidden();
}

$langs->loadLangs(array("bankimport@bankimport", "bills", "banks", "companies"));

llxHeader('', $langs->trans("BANKIMPORT_Reconcile_Title"));

print load_fiche_titre($langs->trans("BANKIMPORT_Reconcile_Title"));

// Get parameters
$accountid = GETPOST('accountid', 'int');
$action = GETPOST('action', 'alpha');
$banklineid = GETPOST('banklineid', 'int');
$invoiceid = GETPOST('invoiceid', 'int');
$invoice_type = GETPOST('invoice_type', 'alpha');
$payment_type = GETPOST('payment_type', 'alpha');
$mark_paid = GETPOST('mark_paid', 'int');

// Handle reconciliation action
if ($action == 'reconcile' && !empty($banklineid) && !empty($invoiceid)) {
    $error = 0;
    
    $db->begin();
    
    try {
        // Load bank line
        $sql = "SELECT b.rowid, b.amount, b.dateo, b.fk_account, b.label, b.fk_type";
        $sql .= " FROM ".MAIN_DB_PREFIX."bank as b";
        $sql .= " WHERE b.rowid = ".((int) $banklineid);
        
        $resql = $db->query($sql);
        if (!$resql) {
            throw new Exception($db->lasterror());
        }
        
        $obj = $db->fetch_object($resql);
        if (!$obj) {
            throw new Exception($langs->trans("BANKIMPORT_Error_BankLineNotFound"));
        }
        
        $amount = abs($obj->amount);
        $datepaye = $db->jdate($obj->dateo);
        $accountid = $obj->fk_account;
        
        // Check if already reconciled
        if (!($obj->fk_type === null || $obj->fk_type === 'IMPORT')) {
            throw new Exception($langs->trans("BANKIMPORT_Error_AlreadyReconciled"));
        }
        
        // Default payment type if not provided
        if (empty($payment_type)) {
            $payment_type = 'VIR'; // VIR (Virement)
        }
        
        if ($invoice_type == 'customer') {
            // Customer invoice payment
            $invoice = new Facture($db);
            $invoice->fetch($invoiceid);
            
            if ($invoice->id <= 0) {
                throw new Exception($langs->trans("BANKIMPORT_Error_InvoiceNotFound"));
            }
            
            if ($invoice->paye == 1) {
                throw new Exception($langs->trans("BANKIMPORT_Error_InvoiceAlreadyPaid"));
            }
            
            // Create payment
            $payment = new Paiement($db);
            $payment->datepaye = $datepaye;
            $payment->amounts = array($invoiceid => $amount);
            // $payment->paiementid = $payment_type;
            $payment->num_payment = '';
            $payment->note_private = $langs->trans("BANKIMPORT_Reconcile_AutoNote");
            
            $payment_id = $payment->create($user);
            
            if ($payment_id < 0) {
                throw new Exception($payment->error);
            }
            
            // Update the bank line to link to payment
            $sql3 = "UPDATE ".MAIN_DB_PREFIX."bank SET";
            $sql3 .= " fk_type = '$payment_type'";
            $sql3 .= ", fk_bordereau = ".((int) $payment_id);
            $sql3 .= ", label = '(CustomerInvoicePayment)'";
            $sql3 .= " WHERE rowid = ".((int) $banklineid);
            
            $resql3 = $db->query($sql3);
            if (!$resql3) {
                throw new Exception($db->lasterror());
            }
            
            // Link payment to bank line in paiement table
            $sql4 = "UPDATE ".MAIN_DB_PREFIX."paiement SET";
            $sql4 .= " fk_bank = ".((int) $banklineid);
            $sql4 .= " WHERE rowid = ".((int) $payment_id);
            
            $db->query($sql4);

            // Create bank_url entries for proper display in bank account
            // Link 1: Bank line -> Payment
            $sql5 = "INSERT INTO ".MAIN_DB_PREFIX."bank_url (fk_bank, url_id, url, type)";
            $sql5 .= " VALUES (".((int) $banklineid).", ".((int) $payment_id).", '";
            $sql5 .= $payment_id."', 'payment')";
            $db->query($sql5);
            
            // Link 2: Bank line -> Invoice
            $sql6 = "INSERT INTO ".MAIN_DB_PREFIX."bank_url (fk_bank, url_id, url, type)";
            $sql6 .= " VALUES (".((int) $banklineid).", ".((int) $invoiceid).", '";
            $sql6 .= $invoice->ref."', 'company')";
            $db->query($sql6);
            
            // Link 3: Bank line -> Third party (company)
            $sql7 = "INSERT INTO ".MAIN_DB_PREFIX."bank_url (fk_bank, url_id, url, type)";
            $sql7 .= " VALUES (".((int) $banklineid).", ".((int) $invoice->socid).", '";
            $sql7 .= $invoice->thirdparty->name."', 'company')";
            $db->query($sql7);
            
            // Mark invoice as paid if requested and fully paid
            if ($mark_paid) {
                // Recalculate remaining amount
                $invoice->fetch($invoiceid);
                $totalpaid = $invoice->getSommePaiement();
                $totalcreditnotes = $invoice->getSumCreditNotesUsed();
                $totaldeposits = $invoice->getSumDepositsUsed();
                $remains = price2num($invoice->total_ttc - $totalpaid - $totalcreditnotes - $totaldeposits, 'MT');
                
                if ($remains <= 0) {
                    $result = $invoice->setPaid($user);
                    if ($result < 0) {
                        throw new Exception($invoice->error);
                    }
                }
            }
            
        } else {
            // Supplier invoice payment
            $invoice = new FactureFournisseur($db);
            $invoice->fetch($invoiceid);
            
            if ($invoice->id <= 0) {
                throw new Exception($langs->trans("BANKIMPORT_Error_InvoiceNotFound"));
            }
            
            if ($invoice->paye == 1) {
                throw new Exception($langs->trans("BANKIMPORT_Error_InvoiceAlreadyPaid"));
            }
            
            // Create payment
            $payment = new PaiementFourn($db);
            $payment->datepaye = $datepaye;
            $payment->amounts = array($invoiceid => $amount);
            $payment->paiementid = $payment_type;
            $payment->num_payment = '';
            $payment->note_private = $langs->trans("BANKIMPORT_Reconcile_AutoNote");
            
            $payment_id = $payment->create($user);
            
            if ($payment_id < 0) {
                throw new Exception($payment->error);
            }
            
            // Update bank line
            $sql3 = "UPDATE ".MAIN_DB_PREFIX."bank SET";
            $sql3 .= " fk_type = 'payment_supplier'";
            $sql3 .= ", fk_bordereau = ".((int) $payment_id);
            $sql3 .= " WHERE rowid = ".((int) $banklineid);
            
            $resql3 = $db->query($sql3);
            if (!$resql3) {
                throw new Exception($db->lasterror());
            }
            
            // Link payment to bank line
            $sql4 = "UPDATE ".MAIN_DB_PREFIX."paiementfourn SET";
            $sql4 .= " fk_bank = ".((int) $banklineid);
            $sql4 .= " WHERE rowid = ".((int) $payment_id);
            
            $db->query($sql4);

            // Create bank_url entries for proper display in bank account
            // Link 1: Bank line -> Supplier Payment
            $sql5 = "INSERT INTO ".MAIN_DB_PREFIX."bank_url (fk_bank, url_id, url, type)";
            $sql5 .= " VALUES (".((int) $banklineid).", ".((int) $payment_id).", '";
            $sql5 .= $payment_id."', 'payment_supplier')";
            $db->query($sql5);
            
            // Link 2: Bank line -> Supplier Invoice
            $sql6 = "INSERT INTO ".MAIN_DB_PREFIX."bank_url (fk_bank, url_id, url, type)";
            $sql6 .= " VALUES (".((int) $banklineid).", ".((int) $invoiceid).", '";
            $sql6 .= $invoice->ref."', 'company')";
            $db->query($sql6);
            
            // Link 3: Bank line -> Third party (supplier)
            $sql7 = "INSERT INTO ".MAIN_DB_PREFIX."bank_url (fk_bank, url_id, url, type)";
            $sql7 .= " VALUES (".((int) $banklineid).", ".((int) $invoice->socid).", '";
            $sql7 .= $invoice->thirdparty->name."', 'company')";
            $db->query($sql7);
            
            // Mark invoice as paid if requested and fully paid
            if ($mark_paid) {
                // Recalculate remaining amount
                $invoice->fetch($invoiceid);
                $totalpaid = $invoice->getSommePaiement();
                $totalcreditnotes = $invoice->getSumCreditNotesUsed();
                $totaldeposits = $invoice->getSumDepositsUsed();
                $remains = price2num($invoice->total_ttc - $totalpaid - $totalcreditnotes - $totaldeposits, 'MT');
                
                if ($remains <= 0) {
                    $result = $invoice->setPaid($user);
                    if ($result < 0) {
                        throw new Exception($invoice->error);
                    }
                }
            }
        }
        
        $db->commit();
        setEventMessages($langs->trans("BANKIMPORT_Reconcile_Success"), null, 'mesgs');
        
    } catch (Exception $e) {
        $db->rollback();
        setEventMessages($e->getMessage(), null, 'errors');
        $error++;
    }
}

// Display form
print '<form action="'.$_SERVER["PHP_SELF"].'" method="post">';
print '<input type="hidden" name="token" value="'.newToken().'">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td colspan="2">'.$langs->trans("BANKIMPORT_Reconcile_SelectAccount").'</td>';
print '</tr>';

// Bank account selection
print '<tr class="oddeven">';
print '<td class="fieldrequired">'.$langs->trans("BANKIMPORT_Bank_account").'</td>';
print '<td>';
$form = new Form($db);
print $form->select_comptes($accountid, 'accountid', 0, '', 1, 0, 'all');
print '</td>';
print '</tr>';

print '<tr>';
print '<td colspan="2" class="center">';
print '<input type="submit" class="button" value="'.$langs->trans("BANKIMPORT_Reconcile_ShowLines").'">';
print '</td>';
print '</tr>';

print '</table>';
print '</form>';

// If account selected, show unreconciled lines
if (!empty($accountid)) {
    print '<br>';
    print '<table class="noborder centpercent">';
    print '<tr class="liste_titre">';
    print '<th>'.$langs->trans("BANKIMPORT_Reconcile_Date").'</th>';
    print '<th>'.$langs->trans("BANKIMPORT_Reconcile_Label").'</th>';
    print '<th class="right">'.$langs->trans("BANKIMPORT_Reconcile_Amount").'</th>';
    print '<th class="center">'.$langs->trans("BANKIMPORT_Reconcile_Actions").'</th>';
    print '</tr>';
    
    // Get unreconciled bank lines
    $sql = "SELECT b.rowid, b.dateo, b.label, b.amount";
    $sql .= " FROM ".MAIN_DB_PREFIX."bank as b";
    $sql .= " WHERE b.fk_account = ".((int) $accountid);
    $sql .= " AND (b.fk_type IS NULL OR b.fk_type = 'IMPORT')";
    $sql .= " AND b.amount != 0";
    $sql .= " ORDER BY b.dateo DESC, b.rowid DESC";
    $sql .= " LIMIT 100";
    
    $resql = $db->query($sql);
    if ($resql) {
        $num = $db->num_rows($resql);
        
        if ($num == 0) {
            print '<tr><td colspan="4" class="opacitymedium center">';
            print $langs->trans("BANKIMPORT_Reconcile_NoUnreconciledLines");
            print '</td></tr>';
        }
        
        for ($i = 0; $i < $num; $i++) {
            $obj = $db->fetch_object($resql);
            
            print '<tr class="oddeven">';
            print '<td>'.dol_print_date($db->jdate($obj->dateo), 'day').'</td>';
            print '<td>'.dol_escape_htmltag($obj->label).'</td>';
            print '<td class="right">'.price($obj->amount).'</td>';
            print '<td class="center">';
            print '<a class="button smallpaddingimp" href="#" onclick="openReconcileModal('.$obj->rowid.', \''.dol_escape_js($obj->label).'\', '.$obj->amount.'); return false;">';
            print $langs->trans("BANKIMPORT_Reconcile_Link");
            print '</a>';
            print '</td>';
            print '</tr>';
        }
    }
    
    print '</table>';
}

// Get payment types for dropdown
$sql = "SELECT id, code, libelle FROM ".MAIN_DB_PREFIX."c_paiement";
$sql .= " WHERE entity IN (".getEntity('c_paiement').")";
$sql .= " AND active = 1";
$sql .= " ORDER BY libelle";
$resql = $db->query($sql);
$payment_types = array();
if ($resql) {
    while ($obj = $db->fetch_object($resql)) {
        $payment_types[] = array(
            'id' => $obj->id,
            'code' => $obj->code,
            'label' => $obj->libelle
        );
    }
}

// Modal for invoice selection
print '
<div id="reconcileModal" style="display:none; position:fixed; z-index:9999; left:0; top:0; width:100%; height:100%; background-color:rgba(0,0,0,0.4);">
    <div style="background-color:#fefefe; margin:3% auto; padding:20px; border:1px solid #888; width:85%; max-width:900px; border-radius:8px; max-height:85vh; overflow-y:auto;">
        <span onclick="closeReconcileModal()" style="color:#aaa; float:right; font-size:28px; font-weight:bold; cursor:pointer;">&times;</span>
        <h3>'.$langs->trans("BANKIMPORT_Reconcile_SelectInvoice").'</h3>
        
        <div id="modalContent">
            <div style="background-color:#f5f5f5; padding:10px; margin-bottom:15px; border-radius:4px;">
                <p style="margin:5px 0;"><strong>'.$langs->trans("BANKIMPORT_Reconcile_BankLine").':</strong> <span id="modalBankLabel"></span></p>
                <p style="margin:5px 0;"><strong>'.$langs->trans("BANKIMPORT_Reconcile_Amount").':</strong> <span id="modalBankAmount"></span></p>
            </div>
            
            <form id="reconcileForm" action="'.$_SERVER["PHP_SELF"].'?accountid='.$accountid.'" method="post">
                <input type="hidden" name="token" value="'.newToken().'">
                <input type="hidden" name="action" value="reconcile">
                <input type="hidden" name="banklineid" id="modalBankLineId">
                <input type="hidden" name="accountid" value="'.$accountid.'">
                
                <table class="noborder centpercent">
                    <tr class="liste_titre">
                        <td colspan="2">'.$langs->trans("BANKIMPORT_Reconcile_PaymentSettings").'</td>
                    </tr>
                    <tr>
                        <td style="width:30%;">'.$langs->trans("BANKIMPORT_Reconcile_InvoiceType").'</td>
                        <td>
                            <select name="invoice_type" id="invoiceType" onchange="searchInvoices()" style="min-width:200px;">
                                <option value="customer">'.$langs->trans("BANKIMPORT_Reconcile_CustomerInvoice").'</option>
                                <option value="supplier">'.$langs->trans("BANKIMPORT_Reconcile_SupplierInvoice").'</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <td>'.$langs->trans("BANKIMPORT_Reconcile_PaymentType").'</td>
                        <td>
                            <select name="payment_type" id="paymentType" style="min-width:200px;">
';

foreach ($payment_types as $pt) {
    $selected = ($pt['code'] == 'VIR') ? 'selected' : '';
    print '<option value="'.$pt['code'].'" '.$selected.'>'.$langs->trans("PaymentType".dol_escape_htmltag($pt['code'])).'</option>';
}

print '
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <td>'.$langs->trans("BANKIMPORT_Reconcile_MarkPaid").'</td>
                        <td>
                            <input type="checkbox" name="mark_paid" id="markPaid" value="1" checked>
                            <label for="markPaid">'.$langs->trans("BANKIMPORT_Reconcile_MarkPaidLabel").'</label>
                        </td>
                    </tr>
                </table>
                
                <br>
                
                <table class="noborder centpercent">
                    <tr class="liste_titre">
                        <td colspan="2">'.$langs->trans("BANKIMPORT_Reconcile_SelectInvoice").'</td>
                    </tr>
                    <tr>
                        <td colspan="2">
                            <input type="text" id="invoiceSearch" placeholder="'.$langs->trans("BANKIMPORT_Reconcile_SearchPlaceholder").'" style="width:100%; padding:8px; margin-bottom:10px;">
                        </td>
                    </tr>
                    <tr>
                        <td colspan="2">
                            <div style="max-height:350px; overflow-y:auto; border:1px solid #ccc; padding:10px; background-color:#fff;">
                                <div id="invoiceList"></div>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td colspan="2" class="center" style="padding-top:15px;">
                            <input type="submit" class="button" value="'.$langs->trans("BANKIMPORT_Reconcile_Confirm").'" id="confirmButton" disabled>
                            <input type="button" class="button button-cancel" value="'.$langs->trans("Cancel").'" onclick="closeReconcileModal()">
                        </td>
                    </tr>
                </table>
                <input type="hidden" name="invoiceid" id="selectedInvoiceId">
            </form>
        </div>
    </div>
</div>

<script>
var currentBankAmount = 0;

function openReconcileModal(banklineid, label, amount) {
    currentBankAmount = Math.abs(amount);
    document.getElementById("modalBankLineId").value = banklineid;
    document.getElementById("modalBankLabel").textContent = label;
    document.getElementById("modalBankAmount").textContent = amount.toFixed(2) + " €";
    document.getElementById("reconcileModal").style.display = "block";
    
    // Auto-select invoice type based on amount sign
    if (amount > 0) {
        document.getElementById("invoiceType").value = "customer";
    } else {
        document.getElementById("invoiceType").value = "supplier";
    }
    
    searchInvoices();
}

function closeReconcileModal() {
    document.getElementById("reconcileModal").style.display = "none";
    document.getElementById("selectedInvoiceId").value = "";
    document.getElementById("confirmButton").disabled = true;
}

function searchInvoices() {
    var type = document.getElementById("invoiceType").value;
    var search = document.getElementById("invoiceSearch").value;
    
    fetch("'.DOL_URL_ROOT.'/custom/bankimport/ajax/search_invoices.php?type=" + type + "&search=" + encodeURIComponent(search) + "&amount=" + currentBankAmount + "&token='.newToken().'")
        .then(response => response.json())
        .then(data => {
            var list = document.getElementById("invoiceList");
            list.innerHTML = "";
            
            if (data.length === 0) {
                list.innerHTML = "<p class=\'opacitymedium\' style=\'text-align:center; padding:20px;\'>'.$langs->trans("BANKIMPORT_Reconcile_NoInvoices").'</p>";
                return;
            }
            
            data.forEach(function(invoice) {
                var div = document.createElement("div");
                div.className = "invoice-item";
                div.style.cssText = "padding:12px; margin:8px 0; border:2px solid #ddd; cursor:pointer; border-radius:6px; transition: all 0.3s;";
                div.innerHTML = "<div style=\'display:flex; justify-content:space-between; align-items:center;\'>" +
                               "<div>" +
                               "<strong style=\'font-size:1.1em;\'>" + invoice.ref + "</strong><br>" + 
                               "<span style=\'color:#666;\'>" + invoice.thirdparty + "</span><br>" + 
                               "<span style=\'color:#999; font-size:0.9em;\'>" + invoice.status + "</span>" +
                               "</div>" +
                               "<div style=\'text-align:right;\'>" +
                               "<strong style=\'font-size:1.2em; color:#4CAF50;\'>" + invoice.amount + " €</strong><br>" +
                               "<span style=\'font-size:0.9em; color:#666;\'>' . $langs->trans("BANKIMPORT_Reconcile_RemainingToPay") . '</span>" +
                               "</div>" +
                               "</div>";
                
                div.onmouseover = function() {
                    if (this.style.backgroundColor !== "rgb(232, 245, 233)") {
                        this.style.backgroundColor = "#f5f5f5";
                    }
                };
                
                div.onmouseout = function() {
                    if (this.style.backgroundColor !== "rgb(232, 245, 233)") {
                        this.style.backgroundColor = "";
                    }
                };
                
                div.onclick = function() {
                    // Remove selection from all items
                    var items = document.getElementsByClassName("invoice-item");
                    for (var i = 0; i < items.length; i++) {
                        items[i].style.backgroundColor = "";
                        items[i].style.borderColor = "#ddd";
                        items[i].style.borderWidth = "2px";
                    }
                    // Select this item
                    div.style.backgroundColor = "#e8f5e9";
                    div.style.borderColor = "#4CAF50";
                    div.style.borderWidth = "2px";
                    document.getElementById("selectedInvoiceId").value = invoice.id;
                    document.getElementById("confirmButton").disabled = false;
                };
                
                list.appendChild(div);
            });
        })
        .catch(error => {
            console.error("Error:", error);
            document.getElementById("invoiceList").innerHTML = "<p class=\'error\' style=\'text-align:center; padding:20px; color:red;\'>Erreur de chargement</p>";
        });
}

document.getElementById("invoiceSearch").addEventListener("keyup", function() {
    searchInvoices();
});

window.onclick = function(event) {
    var modal = document.getElementById("reconcileModal");
    if (event.target == modal) {
        closeReconcileModal();
    }
}
</script>
';

llxFooter();
$db->close();