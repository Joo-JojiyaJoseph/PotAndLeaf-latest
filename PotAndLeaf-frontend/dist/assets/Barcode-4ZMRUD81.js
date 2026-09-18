import{j as s}from"./index-B4LzT4OE.js";import{c as d,a as p}from"./barcode-C88Wpuq5.js";function u({value:t,height:n=56,moduleWidth:i=1.7,showText:o=!0}){if(!t)return null;try{const{bars:e,totalModules:a}=d(t);if(!a)return null;const r=a*i,l=o?18:0;return s.jsxs("svg",{width:r,height:n+l,viewBox:`0 0 ${r} ${n+l}`,className:"max-w-full",role:"img","aria-label":`Barcode ${t}`,children:[e.map((c,m)=>s.jsx("rect",{x:c.x*i,y:0,width:c.width*i,height:n,fill:"#111"},m)),o&&s.jsx("text",{x:r/2,y:n+14,textAnchor:"middle",fontFamily:"monospace",fontSize:"13",fill:"#111",children:t})]})}catch{return null}}function w({barcode:t,name:n,price:i}){const o=p(t,{height:60,moduleWidth:2}),e=window.open("","_blank","width=420,height=320");e&&(e.document.write(`<!doctype html><html><head><title>${t}</title>
    <style>
      @page { size: 50mm 30mm; margin: 2mm; }
      body { font-family: system-ui, sans-serif; text-align: center; margin: 0; padding: 8px; }
      .name { font-size: 12px; font-weight: 600; margin-bottom: 2px; }
      .price { font-size: 12px; margin-top: 2px; }
    </style></head><body>
      <div class="name">${n??""}</div>
      ${o}
      ${i!=null?`<div class="price">₹ ${i}</div>`:""}
      <script>window.onload = function(){ window.print(); }<\/script>
    </body></html>`),e.document.close())}export{u as B,w as p};
