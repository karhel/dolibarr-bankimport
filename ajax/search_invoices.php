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
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

// Security check
if (!$user->hasRight('bankimport', 'import')) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

// Get parameters
$type = GETPOST('type', 'alpha');
$search = GETPOST('search', 'alpha');
$amount = GETPOST('amount', 'float');

$invoices = array();

if ($type == 'customer') {
    // Search customer invoices
    $sql = "SELECT f.rowid, f.ref, f.total_ttc, f.paye, f.fk_statut,";
    $sql .= " s.nom as company_name,";
    $sql .= " (f.total_ttc - SUM(COALESCE(p.amount, 0))) as remaining";
    $sql .= " FROM ".MAIN_DB_PREFIX."facture as f";
    $sql .= " LEFT JOIN ".MAIN_DB_PREFIX."societe as s ON f.fk_soc = s.rowid";
    $sql .= " LEFT JOIN ".MAIN_DB_PREFIX."paiement_facture as pf ON f.rowid = pf.fk_facture";
    $sql .= " LEFT JOIN ".MAIN_DB_PREFIX."paiement as p ON pf.fk_paiement = p.rowid";
    $sql .= " WHERE f.paye = 0";
    $sql .= " AND f.fk_statut = 1"; // Validated
    
    if (!empty($search)) {
        $sql .= " AND (f.ref LIKE '%".$db->escape($search)."%' OR s.nom LIKE '%".$db->escape($search)."%')";
    }
    
    if (!empty($amount)) {
        $tolerance = 0.01;
        $sql .= " GROUP BY f.rowid, f.ref, f.total_ttc, f.paye, f.fk_statut, s.nom";
        $sql .= " HAVING ABS(remaining - ".((float) $amount).") <= ".$tolerance;
    } else {
        $sql .= " GROUP BY f.rowid, f.ref, f.total_ttc, f.paye, f.fk_statut, s.nom";
    }
    
    $sql .= " ORDER BY f.datef DESC";
    $sql .= " LIMIT 50";
    
    $resql = $db->query($sql);
    if ($resql) {
        while ($obj = $db->fetch_object($resql)) {
            $status = $obj->paye ? 'Payée' : ($obj->remaining > 0 ? 'Partiellement payée' : 'Impayée');
            $invoices[] = array(
                'id' => $obj->rowid,
                'ref' => $obj->ref,
                'thirdparty' => $obj->company_name,
                'amount' => number_format($obj->remaining, 2, ',', ' '),
                'status' => $status
            );
        }
    }
} else {
    // Search supplier invoices
    $sql = "SELECT f.rowid, f.ref, f.total_ttc, f.paye, f.fk_statut,";
    $sql .= " s.nom as company_name,";
    $sql .= " (f.total_ttc - SUM(COALESCE(p.amount, 0))) as remaining";
    $sql .= " FROM ".MAIN_DB_PREFIX."facture_fourn as f";
    $sql .= " LEFT JOIN ".MAIN_DB_PREFIX."societe as s ON f.fk_soc = s.rowid";
    $sql .= " LEFT JOIN ".MAIN_DB_PREFIX."paiementfourn_facturefourn as pf ON f.rowid = pf.fk_facturefourn";
    $sql .= " LEFT JOIN ".MAIN_DB_PREFIX."paiementfourn as p ON pf.fk_paiementfourn = p.rowid";
    $sql .= " WHERE f.paye = 0";
    $sql .= " AND f.fk_statut = 1"; // Validated
    
    if (!empty($search)) {
        $sql .= " AND (f.ref LIKE '%".$db->escape($search)."%' OR s.nom LIKE '%".$db->escape($search)."%')";
    }
    
    if (!empty($amount)) {
        $tolerance = 0.01;
        $sql .= " GROUP BY f.rowid, f.ref, f.total_ttc, f.paye, f.fk_statut, s.nom";
        $sql .= " HAVING ABS(remaining - ".((float) abs($amount)).") <= ".$tolerance;
    } else {
        $sql .= " GROUP BY f.rowid, f.ref, f.total_ttc, f.paye, f.fk_statut, s.nom";
    }
    
    $sql .= " ORDER BY f.datef DESC";
    $sql .= " LIMIT 50";
    
    $resql = $db->query($sql);
    if ($resql) {
        while ($obj = $db->fetch_object($resql)) {
            $status = $obj->paye ? 'Payée' : ($obj->remaining > 0 ? 'Partiellement payée' : 'Impayée');
            $invoices[] = array(
                'id' => $obj->rowid,
                'ref' => $obj->ref,
                'thirdparty' => $obj->company_name,
                'amount' => number_format($obj->remaining, 2, ',', ' '),
                'status' => $status
            );
        }
    }
}

header('Content-Type: application/json');
echo json_encode($invoices);