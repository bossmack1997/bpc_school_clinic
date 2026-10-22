from pathlib import Path
p=Path('api/inventory.php');s=p.read_text();a=s.index('    // --- Duplicate handling:');b=s.index('        if ($isMerge)',a)
s=s[:a]+'''    // Serialize exact batch identities; never pool different expiry/batch records by name.
    $identity=[$medicineName,$genericName,$brand,$unit,$batch,$expDate,$supplier];
    $mergeLock='bpc_med_add_'.sha1(json_encode($identity));
    $lock=$db->prepare('SELECT GET_LOCK(?,5)');$lock->execute([$mergeLock]);
    if((int)$lock->fetchColumn()!==1){http_response_code(409);json_response(false,[],'This medicine is being updated. Check stock before retrying.');}
    try {
        $db->beginTransaction();
        $dupStmt=$db->prepare("SELECT id,quantity,medicine_name FROM medicines WHERE medicine_name=? AND COALESCE(generic_name,'')=? AND COALESCE(brand,'')=? AND unit=? AND COALESCE(batch_number,'')=? AND COALESCE(expiration_date,'')=? AND COALESCE(supplier,'')=? AND is_archived=0 ORDER BY id LIMIT 1 FOR UPDATE");
        $dupStmt->execute($identity);$existingMed=$dupStmt->fetch();$isMerge=$existingMed!==false;
'''+s[b:]
s=s.replace("        $db->commit();\n        json_response(true, ['id' => $medicineId", "        $db->commit();\n        $db->prepare('SELECT RELEASE_LOCK(?)')->execute([$mergeLock]);\n        json_response(true, ['id' => $medicineId",1)
s=s.replace("        error_log('Add medicine failed:", "        $db->prepare('SELECT RELEASE_LOCK(?)')->execute([$mergeLock]);\n        error_log('Add medicine failed:",1)
s=s.replace("'Medicine ' . ($hard ? 'deleted' : 'archived') . '.'", "'Medicine archived. Existing history was preserved.'")
p.write_text(s)
p=Path('includes/inventory_ui.php');s=p.read_text().replace('class="btn btn-sm btn-outline-danger del-med" data-id="${medicine.id}" title="Delete"','class="btn btn-sm btn-outline-danger del-med" data-id="${medicine.id}" title="Archive"')
s=s.replace("    const id = $('#medId').val();\n    const action", "    const submit=$(this).find('[type=\"submit\"]');if(submit.prop('disabled'))return;\n    const id = $('#medId').val();\n    const action",1)
s=s.replace("    $.post(APP + '/api/inventory.php', $(this).serialize() + '&action=' + action", "    submit.prop('disabled',true);\n    $.post(APP + '/api/inventory.php', $(this).serialize() + '&action=' + action",1)
s=s.replace("      else toastError(res.message);\n    }, 'json');\n  });\n\n  // ============================================================\n  // Stock update", "      else toastError(res.message);\n    }, 'json').fail(function(xhr){toastError((xhr.responseJSON&&xhr.responseJSON.message)||'Connection lost. Check current inventory before retrying.');}).always(function(){submit.prop('disabled',false);});\n  });\n\n  // ============================================================\n  // Stock update",1)
s=s.replace("  $('#stockForm').on('submit', function (e) {\n    e.preventDefault();", "  $('#stockForm').on('submit', function (e) {\n    e.preventDefault();var submit=$(this).find('[type=\"submit\"]');if(submit.prop('disabled'))return;submit.prop('disabled',true);",1)
s=s.replace("      else toastError(res.message);\n    }, 'json');\n  });\n\n  // ============================================================\n  // Delete", "      else toastError(res.message);\n    }, 'json').fail(function(xhr){toastError((xhr.responseJSON&&xhr.responseJSON.message)||'Connection lost. Check stock before retrying.');}).always(function(){submit.prop('disabled',false);});\n  });\n\n  // ============================================================\n  // Delete",1)
p.write_text(s)
