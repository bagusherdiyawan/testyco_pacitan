(() => {
 const rows=document.querySelector('#pos-lines'), add=document.querySelector('#add-pos-line');
 if(!rows||!add)return;
 let next=rows.children.length;
 function filter(row,clear=false){
  const product=row.querySelector('[data-product]'), unit=row.querySelector('[data-unit]'),stock=row.querySelector('[data-stock]');
  const pack=product.selectedOptions[0]?.dataset.pack;
  for(const option of stock.options){if(!option.value)continue;const eligible=option.dataset.productId===product.value&&(unit.value==='pack'?(option.dataset.bucket==='usable'&&option.dataset.pack===pack):option.dataset.bucket!=='usable');option.hidden=!eligible;option.disabled=!eligible;}
  if(clear||stock.selectedOptions[0]?.disabled)stock.value='';
 }
 rows.addEventListener('change',e=>{if(e.target.matches('[data-product],[data-unit]'))filter(e.target.closest('.pos-row'),true);});
 rows.addEventListener('click',e=>{const button=e.target.closest('[data-remove-pos]');if(button&&rows.children.length>1)button.closest('.pos-row').remove();});
 add.addEventListener('click',()=>{if(rows.children.length>=100)return;const copy=rows.firstElementChild.cloneNode(true);copy.querySelector('legend').textContent=`Item ${next+1}`;copy.querySelectorAll('[name]').forEach(el=>{el.name=el.name.replace(/lines\[\d+\]/,`lines[${next}]`);if(el.tagName==='INPUT')el.value='1';});rows.append(copy);filter(copy,true);next++;});
 for(const row of rows.children)filter(row);
})();
