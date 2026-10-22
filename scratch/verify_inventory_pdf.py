import sys
from pathlib import Path
sys.path.insert(0,'scratch/pdfqa_deps')
import pymupdf as fitz
folder=Path('scratch/inventory-upgrade-qa');folder.mkdir(exist_ok=True)
for name,path in [('nurse','scratch/inventory-upgrade-qa/nurse-endpoint.pdf'),('co','scratch/inventory-upgrade-qa/co-endpoint.pdf'),('multiple','scratch/requisition-document-qa/multiple.pdf')]:
 doc=fitz.open(path);text='\n'.join(page.get_text() for page in doc)
 for required in ['Medicine / Generic / Brand','Supplier','Suggested','Prepared By:','Reviewed By:','Approved By:','Signature:','Draft']:
  assert required in text,(name,required)
 for page in doc:
  for block in page.get_text('blocks'):
   assert block[0]>=-1 and block[1]>=-1 and block[2]<=page.rect.width+1 and block[3]<=page.rect.height+1,(name,'text outside page')
 print('PASS PDF',name,'pages',len(doc),'required fields and page bounds')
 doc[-1].get_pixmap(matrix=fitz.Matrix(1.25,1.25)).save(str(folder/(name+'-last-page.png')))
 if name=='co':
  assert 'Generic Fixture' in text and 'Brand Fixture' in text and 'Supplier Fixture' in text and '15' in text
