const COLOR_MAP={
  blanco:'#ffffff',white:'#ffffff',negro:'#111111',black:'#111111',rojo:'#d9232e',red:'#d9232e',azul:'#2d5fb8',blue:'#2d5fb8',verde:'#4f7d57',green:'#4f7d57',beige:'#d9c7aa',crema:'#efe4cf',gris:'#888b8d',gray:'#888b8d',grey:'#888b8d',rosa:'#e7a2b4',pink:'#e7a2b4',naranja:'#f58a33',orange:'#f58a33',amarillo:'#e6c53f',yellow:'#e6c53f',marron:'#76513e',marrón:'#76513e',brown:'#76513e',violeta:'#7654a8',morado:'#7654a8',lila:'#b59ac8'
};

const norm=(value)=>String(value||'').normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase().trim();
const colorCss=(name)=>COLOR_MAP[norm(name)]||'#d8d5ce';

function parseOptions(select){
  return Array.from(select.options).filter(o=>o.value).map(o=>{
    const parts=o.textContent.split('·').map(x=>x.trim());
    return {value:o.value,size:parts[0]||'',color:parts[1]||'',option:o};
  });
}

function enhanceCard(card){
  if(card.dataset.variantEnhanced==='1')return;
  const select=card.querySelector('.variant-select');
  const controls=card.querySelector('.product-controls');
  if(!select||!controls)return;
  const variants=parseOptions(select);
  if(!variants.length)return;

  card.dataset.variantEnhanced='1';
  const wrapper=document.createElement('div');wrapper.className='visual-variant-picker';
  const sizeBlock=document.createElement('div');sizeBlock.className='visual-variant-block';
  const sizeHead=document.createElement('div');sizeHead.className='visual-variant-head';sizeHead.innerHTML='<span>Talle</span><strong class="selected-size"></strong>';
  const sizeOptions=document.createElement('div');sizeOptions.className='size-options';
  sizeBlock.append(sizeHead,sizeOptions);

  const colorBlock=document.createElement('div');colorBlock.className='visual-variant-block';
  const colorHead=document.createElement('div');colorHead.className='visual-variant-head';colorHead.innerHTML='<span>Color</span><strong class="selected-color"></strong>';
  const colorOptions=document.createElement('div');colorOptions.className='color-options';
  colorBlock.append(colorHead,colorOptions);
  wrapper.append(sizeBlock,colorBlock);
  controls.before(wrapper);
  select.classList.add('variant-select-fallback');

  let selected=variants.find(v=>v.value===select.value)||variants[0];
  let selectedSize=selected.size;
  let selectedColor=selected.color;

  function availableColorsForSize(size){return [...new Set(variants.filter(v=>v.size===size).map(v=>v.color))];}
  function availableSizesForColor(color){return [...new Set(variants.filter(v=>v.color===color).map(v=>v.size))];}

  function chooseVariant(size,color){
    let match=variants.find(v=>v.size===size&&v.color===color);
    if(!match)match=variants.find(v=>v.size===size)||variants.find(v=>v.color===color)||variants[0];
    selected=match;selectedSize=match.size;selectedColor=match.color;select.value=match.value;select.dispatchEvent(new Event('change',{bubbles:true}));render();
  }

  function render(){
    sizeOptions.replaceChildren();colorOptions.replaceChildren();
    const sizes=[...new Set(variants.map(v=>v.size))];
    const colors=[...new Set(variants.map(v=>v.color))];
    sizeHead.querySelector('.selected-size').textContent=selectedSize;
    colorHead.querySelector('.selected-color').textContent=selectedColor;

    const allowedSizes=availableSizesForColor(selectedColor);
    for(const size of sizes){
      const b=document.createElement('button');b.type='button';b.className='size-choice'+(size===selectedSize?' active':'');b.textContent=size;b.setAttribute('aria-label',`Seleccionar talle ${size}`);if(!allowedSizes.includes(size))b.classList.add('unavailable');b.addEventListener('click',()=>chooseVariant(size,selectedColor));sizeOptions.append(b);
    }

    const allowedColors=availableColorsForSize(selectedSize);
    for(const color of colors){
      const b=document.createElement('button');b.type='button';b.className='color-choice'+(color===selectedColor?' active':'');b.style.setProperty('--swatch',colorCss(color));b.title=color;b.setAttribute('aria-label',`Seleccionar color ${color}`);if(!allowedColors.includes(color))b.classList.add('unavailable');b.addEventListener('click',()=>chooseVariant(selectedSize,color));colorOptions.append(b);
    }
  }

  render();
}

function enhanceAll(){document.querySelectorAll('.product-card').forEach(enhanceCard)}
const observer=new MutationObserver(enhanceAll);observer.observe(document.documentElement,{subtree:true,childList:true});
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',enhanceAll);else enhanceAll();
