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
if (!$user->hasRight('bankimport', 'reconcile')) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

// Get parameters
$search = GETPOST('search', 'alpha');
$amount = GETPOST('amount', 'float');

$items = array();

// Search social contributions (charges sociales)
$sql = "SELECT cs.rowid, cs.libelle, cs.amount, cs.paye, cs.date_ech,";
$sql .= " cs.periode,";
$sql .= " (cs.amount - COALESCE(SUM(ps.amount), 0)) as remaining";
$sql .= " FROM ".MAIN_DB_PREFIX."chargesociales as cs";
$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."paiementcharge as ps ON cs.rowid = ps.fk_charge";
$sql .= " WHERE cs.paye = 0";

if (!empty($search)) {
    $sql .= " AND (cs.libelle LIKE '%".$db->escape($search)."%' OR cs.periode LIKE '%".$db->escape($search)."%')";
}

$sql .= " GROUP BY cs.rowid, cs.libelle, cs.amount, cs.paye, cs.date_ech, cs.periode";

if (!empty($amount)) {
    $tolerance = 0.01;
    $sql .= " HAVING ABS(remaining - ".((float) abs($amount)).") <= ".$tolerance;
}

$sql .= " ORDER BY cs.date_ech DESC";
$sql .= " LIMIT 50";

$resql = $db->query($sql);
if ($resql) {
    while ($obj = $db->fetch_object($resql)) {
        $status = $obj->paye ? 'Payée' : ($obj->remaining > 0 ? 'Partiellement payée' : 'Impayée');
        
        // Format period
        $period = '';
        if ($obj->periode) {
            $period = dol_print_date($db->jdate($obj->periode), '%B %Y');
        }
        if ($obj->date_ech) {
            if ($period) {
                $period .= ' - Échéance: ' . dol_print_date($db->jdate($obj->date_ech), 'day');
            } else {
                $period = 'Échéance: ' . dol_print_date($db->jdate($obj->date_ech), 'day');
            }
        }
        
        $items[] = array(
            'id' => $obj->rowid,
            'label' => $obj->libelle,
            'period' => $period,
            'amount' => number_format($obj->remaining, 2, ',', ' '),
            'status' => $status
        );
    }
}

header('Content-Type: application/json');
echo json_encode($items);