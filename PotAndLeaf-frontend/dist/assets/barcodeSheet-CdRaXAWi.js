import{a as l}from"./barcode-C88Wpuq5.js";const s=e=>String(e??"").replace(/[&<>"']/g,t=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"})[t]),m=e=>"₹"+Number(e||0).toLocaleString("en-IN",{minimumFractionDigits:0,maximumFractionDigits:2});function b(e,t={}){const{columns:o=4,showPrice:a=!0,showName:n=!0}=t;if(!e.length)return;const c=e.map(i=>{const p=i.barcode?l(i.barcode,{height:44,moduleWidth:1.4,showText:!0}):'<div class="nobc">no barcode</div>';return`<div class="label">
      ${n?`<div class="name">${s(i.name)}</div>`:""}
      <div class="bc">${p}</div>
      <div class="row">
        <span class="sku">${s(i.sku??"")}</span>
        ${a&&i.price!=null?`<span class="price">${m(i.price)}</span>`:""}
      </div>
    </div>`}).join(""),d=`<!doctype html><html><head><meta charset="utf-8"><title>Barcode labels</title>
  <style>
    * { box-sizing: border-box; }
    body { font-family: -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 0; padding: 8mm; }
    .sheet { display: grid; grid-template-columns: repeat(${o}, 1fr); gap: 4mm; }
    .label { border: 1px dashed #ccc; border-radius: 4px; padding: 5px 6px; text-align: center; page-break-inside: avoid; }
    .name { font-size: 10px; font-weight: 600; color: #222; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-bottom: 2px; }
    .bc svg { max-width: 100%; height: auto; }
    .nobc { font-size: 9px; color: #b00; padding: 12px 0; }
    .row { display: flex; justify-content: space-between; align-items: center; margin-top: 2px; }
    .sku { font-size: 9px; color: #777; }
    .price { font-size: 12px; font-weight: 700; color: #2f5233; }
    .bar { text-align: right; max-width: 100%; margin: 0 auto 10px; }
    .btn { background: #2f5233; color: #fff; border: 0; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-size: 13px; }
    @media print { body { padding: 0; } .noprint { display: none; } .label { border-color: transparent; } }
  </style></head><body>
    <div class="noprint bar"><button class="btn" onclick="window.print()">Print / Save as PDF</button></div>
    <div class="sheet">${c}</div>
    <script>window.onload = function(){ setTimeout(function(){ window.print(); }, 300); };<\/script>
  </body></html>`,r=window.open("","_blank","width=1000,height=1000");r&&(r.document.open(),r.document.write(d),r.document.close())}function g(e){const t=[];for(const o of e){const a=Math.max(0,Math.floor(Number(o.copies)||0));for(let n=0;n<a;n++)t.push({name:o.name,sku:o.sku,barcode:o.barcode,price:o.price})}return t}export{g as e,b as p};
