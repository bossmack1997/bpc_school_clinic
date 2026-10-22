from pathlib import Path
p=Path('includes/ai/MICAContextService.php');s=p.read_text()
import re
for method in ['getLowStockMedicines','getExpiringMedicines']:
 s=re.sub(r'(public function '+method+r'\([^)]*\): array\s*\{)',r"\1\n        if(!can_view_inventory($this->user,$this->db))return [];",s)
for name in ['lowStockMeds','expiringMeds','expiredMeds']:
 s=re.sub(r'(\$'+name+r' = )(\(int\)\$this->db->query\([^\n]+?->fetchColumn\(\));',r'\1can_view_inventory($this->user,$this->db)?\2:null;',s)
p.write_text(s)
p=Path('includes/ai/DatabaseContext.php');s=p.read_text().replace('                $parts[] = "- Low Stock / Unavailable Medicines: {$lowMeds}";\n                $parts[] = "- Low Stock / Alert Medicines: {$lowMeds}";', '                if(can_view_inventory($this->user,$this->db))$parts[] = "- Low Stock / Alert Medicines: {$lowMeds}";');p.write_text(s)
# Do not surface inventory recommendation contents to accounts without that delegation.
p=Path('includes/ai/MICAActionService.php');s=p.read_text();pos=s.index('    public function createReorderRequest');a=s.rfind('        return $stmt->fetchAll(PDO::FETCH_ASSOC);',0,pos)
if a!=-1:s=s[:a]+s[a:].replace('        return $stmt->fetchAll(PDO::FETCH_ASSOC);', "        $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);\n        if(!can_view_inventory($this->user,$this->db))$rows=array_values(array_filter($rows,fn($row)=>!in_array($row['target_type']??'', ['medicine','medicines','inventory'],true)&&!preg_match('/stock|reorder|expir|inventory/i',$row['action_type']??'')));\n        return $rows;",1)
p.write_text(s)
# Useful errors for existing stats/category and write handlers.
p=Path('includes/inventory_ui.php');s=p.read_text().replace("      if (!payload.success) return;\n      const s = payload;", "      if (!payload.success){$('#inventoryFeedback').text(payload.message||'Summary unavailable.');return;}\n      const s = payload;",1)
s=s.replace("$('#statCats').text(Number(s.total_categories || 0)).attr('data-count', Number(s.total_categories || 0));\n    }, 'json');", "$('#statCats').text(Number(s.total_categories || 0)).attr('data-count', Number(s.total_categories || 0));\n    }, 'json').fail(function(xhr){$('#inventoryFeedback').text((xhr.responseJSON&&xhr.responseJSON.message)||'Summary unavailable. Refresh to retry.');});",1)
s=s.replace("      if (!res.success) return;\n      const $sel = $('#invCategory');", "      if (!res.success){$('#inventoryFeedback').text(res.message||'Categories unavailable.');loadInventory();return;}\n      const $sel = $('#invCategory');",1)
s=s.replace("      loadInventory();\n    }, 'json');\n  }\n\n  // ============================================================\n  // Load inventory table", "      loadInventory();\n    }, 'json').fail(function(xhr){$('#inventoryFeedback').text((xhr.responseJSON&&xhr.responseJSON.message)||'Categories unavailable.');loadInventory();});\n  }\n\n  // ============================================================\n  // Load inventory table",1)
p.write_text(s)
