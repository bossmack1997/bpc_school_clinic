from pathlib import Path
p=Path('scratch/backup_role_restructure.php');s=p.read_text();s=s.replace("$zip->addFile($f->getPathname(),substr($f->getPathname(),strlen(BASE_PATH)+1));$files++;", "$bytes=@file_get_contents($f->getPathname());if($bytes!==false){$zip->addFromString(substr($f->getPathname(),strlen(BASE_PATH)+1),$bytes);$files++;}");p.write_text(s)
