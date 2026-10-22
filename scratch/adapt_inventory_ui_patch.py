from pathlib import Path
p=Path('scratch/upgrade_inventory_ui.py');s=p.read_text().replace("start=old.index('      const rows = res.data.map');end=old.index('      if (dt) dt.destroy();',start)","start=old.index('      const rows = incomingRows.map');end=old.index('      if (dt)',start)")
s=s.replace("old[start:end]", "old[start:end].replace('incomingRows.map','res.data.map')")
p.write_text(s)
ui=Path('includes/inventory_ui.php');Path('scratch/backups/inventory-mica-20261022/inventory-ui-observed-during-work.php').write_bytes(ui.read_bytes())
