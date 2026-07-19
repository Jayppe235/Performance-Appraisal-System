const fs = require('fs');
const path = require('path');

const filePath = path.join(__dirname, 'includes/evaluation_cards.php');
let content = fs.readFileSync(filePath, 'utf-8');

const searchStr = '.form-a-submit-btn:not(:disabled):hover{background:#16a34a}';
const insertCss = `.form-b-evidence-box{padding:16px 12px;background:#f8fafc;border-radius:12px;margin-top:12px;border:1px solid #e2e8f0}
.form-b-evidence-section{margin-bottom:10px}
.form-b-evidence-section:last-child{margin-bottom:0}
.form-b-evidence-section label{display:block;font-size:.8rem;font-weight:700;color:#334155;margin-top:10px;margin-bottom:4px}
.form-b-evidence-section textarea{width:100%;min-height:60px;padding:10px 12px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:.85rem;font-family:inherit;resize:vertical;transition:border-color .2s;box-sizing:border-box}
.form-b-evidence-section textarea:focus{border-color:#6366f1;outline:none;box-shadow:0 0 0 3px rgba(99,102,241,.1)}
.evidence-heading,.explanation-heading{padding:10px 12px;background:#eef2ff;border-radius:8px;margin-bottom:8px}
.evidence-heading strong,.explanation-heading strong{font-size:.85rem;color:#4338ca}
.required-mark{color:#dc2626;font-size:.75rem;font-weight:700;margin-left:4px}
.form-b-progress-hint{text-align:center;padding:16px 12px;color:#64748b;font-size:.85rem;font-weight:500}
`;

const idx = content.indexOf(searchStr);
if (idx === -1) {
  console.error('ERROR: Could not find insertion point');
  // Try with different whitespace
  const searchStr2 = ':not(:disabled):hover{background:#16a34a}';
  const idx2 = content.indexOf(searchStr2);
  if (idx2 !== -1) {
    const after = idx2 + searchStr2.length;
    content = content.slice(0, after) + insertCss + content.slice(after);
    fs.writeFileSync(filePath, content, 'utf-8');
    console.log('CSS inserted successfully (alternate match) at position ' + after);
  } else {
    process.exit(1);
  }
} else {
  const after = idx + searchStr.length;
  content = content.slice(0, after) + insertCss + content.slice(after);
  fs.writeFileSync(filePath, content, 'utf-8');
  console.log('CSS inserted successfully at position ' + after);
}
