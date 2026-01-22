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
require_once DOL_DOCUMENT_ROOT.'/compta/sociales/class/chargesociales.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/sociales/class/paymentsocialcontribution.class.php';

// Security check
if (!$user->hasRight('bankimport', 'import')) {
    accessforbidden();
}

$langs->loadLangs(array("bankimport@bankimport", "compta", "banks"));

llxHeader('', $langs->trans("BANKIMPORT_Reconcile_Payments_Title"));

print load_fiche_titre($langs->trans("BANKIMPORT_Reconcile_Payments_Title"));

// Get parameters
$accountid = GETPOST('accountid', 'int');
$action = GETPOST('action', 'alpha');
$banklineid = GETPOST('banklineid', 'int');
$socialid = GETPOST('socialid', 'int');
$payment_type = GETPOST('payment_type', 'alpha');
$mark_paid = GETPOST('mark_paid', 'int');

// Handle reconciliation action
if ($action == 'reconcile' && !empty($banklineid) && !empty($socialid)) {
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
        
        // Default payment type
        if (empty($payment_type)) {
            $payment_type = 'VIR';
        }
        
        // Social contribution payment
        $socialcontrib = new ChargeSociales($db);
        $socialcontrib->fetch($socialid);
        
        if ($socialcontrib->id <= 0) {
            throw new Exception($langs->trans("BANKIMPORT_Error_SocialContribNotFound"));
        }
        
        if ($socialcontrib->paye == 1) {
            throw new Exception($langs->trans("BANKIMPORT_Error_SocialContribAlreadyPaid"));
        }
        
        // Create payment for social contribution
        $payment = new PaymentSocialContribution($db);
        $payment->chid = $socialid;
        $payment->datep = $datepaye;
        $payment->amounts = array($socialid => $amount);
        $payment->paiementtype = $payment_type;
        $payment->num_payment = '';
        $payment->note_private = $langs->trans("BANKIMPORT_Reconcile_AutoNote");
        
        $payment_id = $payment->create($user);

        var_dump($payment); die;
        
        if ($payment_id < 0) {
            throw new Exception($payment->error);
        }
        
        // Add payment to bank - this creates a new bank line
        $result = $payment->addPaymentToBank($user, 'payment_sc', '(SocialContributionPayment)', $accountid, '', '');
        
        if ($result < 0) {
            throw new Exception($payment->error);
        }
        
        $banklineid_new = $result;
        
        // Update the imported bank line instead of keeping the duplicate
        $sql3 = "UPDATE ".MAIN_DB_PREFIX."bank SET";
        $sql3 .= " fk_type = 'payment_sc'";
        $sql3 .= ", fk_bordereau = ".((int) $payment_id);
        $sql3 .= ", label = '(SocialContributionPayment)'";
        $sql3 .= " WHERE rowid = ".((int) $banklineid);
        
        $resql3 = $db->query($sql3);
        if (!$resql3) {
            throw new Exception($db->lasterror());
        }
        
        // Delete the duplicate line created by addPaymentToBank
        if ($banklineid_new != $banklineid) {
            $sql_del = "DELETE FROM ".MAIN_DB_PREFIX."bank WHERE rowid = ".((int) $banklineid_new);
            $db->query($sql_del);
            
            // Also delete any bank_url entries for the duplicate
            $sql_del2 = "DELETE FROM ".MAIN_DB_PREFIX."bank_url WHERE fk_bank = ".((int) $banklineid_new);
            $db->query($sql_del2);
        }
        
        // Update payment to point to our bank line
        $sql4 = "UPDATE ".MAIN_DB_PREFIX."payment_sc SET";
        $sql4 .= " fk_bank = ".((int) $banklineid);
        $sql4 .= " WHERE rowid = ".((int) $payment_id);
        
        $db->query($sql4);
        
        // Create bank_url entry
        $sql5 = "INSERT INTO ".MAIN_DB_PREFIX."bank_url (fk_bank, url_id, url, type)";
        $sql5 .= " VALUES (".((int) $banklineid).", ".((int) $payment_id).", '";
        $sql5 .= $payment_id."', 'payment_sc')";
        $db->query($sql5);
        
        // Mark social contribution as paid if requested and fully paid
        if ($mark_paid) {
            $socialcontrib->fetch($socialid);
            $totalpaid = $socialcontrib->getSommePaiement();
            $remains = price2num($socialcontrib->amount - $totalpaid, 'MT');
            
            if ($remains <= 0) {
                $result = $socialcontrib->setPaid($user);
                if ($result < 0) {
                    throw new Exception($socialcontrib->error);
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
    
    // Get unreconciled bank lines (typically negative amounts for payments)
    $sql = "SELECT b.rowid, b.dateo, b.label, b.amount";
    $sql .= " FROM ".MAIN_DB_PREFIX."bank as b";
    $sql .= " WHERE b.fk_account = ".((int) $accountid);
    $sql .= " AND (b.fk_type IS NULL OR b.fk_type = 'IMPORT')";
    $sql .= " AND b.amount < 0"; // Only show negative amounts (payments out)
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

// Get payment types
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

// Modal for social contribution selection
print '
<div id="reconcileModal" style="display:none; position:fixed; z-index:9999; left:0; top:0; width:100%; height:100%; background-color:rgba(0,0,0,0.4);">
    <div style="background-color:#fefefe; margin:3% auto; padding:20px; border:1px solid #888; width:85%; max-width:900px; border-radius:8px; max-height:85vh; overflow-y:auto;">
        <span onclick="closeReconcileModal()" style="color:#aaa; float:right; font-size:28px; font-weight:bold; cursor:pointer;">&times;</span>
        <h3>'.$langs->trans("BANKIMPORT_Reconcile_SelectSocialContrib").'</h3>
        
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
                        <td style="width:30%;">'.$langs->trans("BANKIMPORT_Reconcile_PaymentType").'</td>
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
                        <td colspan="2">'.$langs->trans("BANKIMPORT_Reconcile_SelectSocialContrib").'</td>
                    </tr>
                    <tr>
                        <td colspan="2">
                            <input type="text" id="socialSearch" placeholder="'.$langs->trans("BANKIMPORT_Reconcile_SearchPlaceholder").'" style="width:100%; padding:8px; margin-bottom:10px;">
                        </td>
                    </tr>
                    <tr>
                        <td colspan="2">
                            <div style="max-height:350px; overflow-y:auto; border:1px solid #ccc; padding:10px; background-color:#fff;">
                                <div id="socialList"></div>
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
                <input type="hidden" name="socialid" id="selectedSocialId">
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
    
    searchSocials();
}

function closeReconcileModal() {
    document.getElementById("reconcileModal").style.display = "none";
    document.getElementById("selectedSocialId").value = "";
    document.getElementById("confirmButton").disabled = true;
}

function searchSocials() {
    var search = document.getElementById("socialSearch").value;
    
    var url = "'.DOL_URL_ROOT.'/custom/bankimport/ajax/search_social.php?search=" + encodeURIComponent(search) + 
              "&amount=" + currentBankAmount + 
              "&token='.newToken().'";
    
    fetch(url)
        .then(response => response.json())
        .then(data => {
            var list = document.getElementById("socialList");
            list.innerHTML = "";
            
            if (data.length === 0) {
                list.innerHTML = "<p class=\'opacitymedium\' style=\'text-align:center; padding:20px;\'>'.$langs->trans("BANKIMPORT_Reconcile_NoItems").'</p>";
                return;
            }
            
            data.forEach(function(social) {
                var div = document.createElement("div");
                div.className = "social-item";
                div.style.cssText = "padding:12px; margin:8px 0; border:2px solid #ddd; cursor:pointer; border-radius:6px; transition: all 0.3s;";
                div.innerHTML = "<div style=\'display:flex; justify-content:space-between; align-items:center;\'>" +
                               "<div>" +
                               "<strong style=\'font-size:1.1em;\'>" + social.label + "</strong><br>" + 
                               "<span style=\'color:#666;\'>" + social.period + "</span><br>" + 
                               "<span style=\'color:#999; font-size:0.9em;\'>" + social.status + "</span>" +
                               "</div>" +
                               "<div style=\'text-align:right;\'>" +
                               "<strong style=\'font-size:1.2em; color:#FF5722;\'>" + social.amount + " €</strong><br>" +
                               "<span style=\'font-size:0.9em; color:#666;\'>' . $langs->trans("BANKIMPORT_Reconcile_RemainingToPay") . '</span>" +
                               "</div>" +
                               "</div>";
                
                div.onmouseover = function() {
                    if (this.style.backgroundColor !== "rgb(255, 235, 238)") {
                        this.style.backgroundColor = "#f5f5f5";
                    }
                };
                
                div.onmouseout = function() {
                    if (this.style.backgroundColor !== "rgb(255, 235, 238)") {
                        this.style.backgroundColor = "";
                    }
                };
                
                div.onclick = function() {
                    var items = document.getElementsByClassName("social-item");
                    for (var i = 0; i < items.length; i++) {
                        items[i].style.backgroundColor = "";
                        items[i].style.borderColor = "#ddd";
                        items[i].style.borderWidth = "2px";
                    }
                    div.style.backgroundColor = "#ffebee";
                    div.style.borderColor = "#FF5722";
                    div.style.borderWidth = "2px";
                    document.getElementById("selectedSocialId").value = social.id;
                    document.getElementById("confirmButton").disabled = false;
                };
                
                list.appendChild(div);
            });
        })
        .catch(error => {
            console.error("Error:", error);
            document.getElementById("socialList").innerHTML = "<p class=\'error\' style=\'text-align:center; padding:20px; color:red;\'>Erreur de chargement</p>";
        });
}

document.getElementById("socialSearch").addEventListener("keyup", function() {
    searchSocials();
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