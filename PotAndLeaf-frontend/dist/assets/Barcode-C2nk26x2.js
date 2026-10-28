import{j as s}from"./index-Coxw0JXm.js";import{c as d,a as f}from"./barcode-C88Wpuq5.js";function w({value:t,height:i=56,moduleWidth:n=1.7,showText:o=!0}){if(!t)return null;try{const{bars:e,totalModules:a}=d(t);if(!a)return null;const r=a*n,l=o?18:0;return s.jsxs("svg",{width:r,height:i+l,viewBox:`0 0 ${r} ${i+l}`,className:"max-w-full",role:"img","aria-label":`Barcode ${t}`,children:[s.jsx("rect",{x:0,y:0,width:r,height:i+l,fill:"#fff",rx:3}),e.map((c,m)=>s.jsx("rect",{x:c.x*n,y:0,width:c.width*n,height:i,fill:"#111"},m)),o&&s.jsx("text",{x:r/2,y:i+14,textAnchor:"middle",fontFamily:"monospace",fontSize:"13",fill:"#111",children:t})]})}catch{return null}}function u({barcode:t,name:i,price:n}){const o=f(t,{height:60,moduleWidth:2}),e=window.open("","_blank","width=420,height=320");e&&(e.document.write(`<!doctype html><html><head><title>${t}</title>
    <style>
      @page { size: 50mm 30mm; margin: 2mm; }
      body { font-family: system-ui, sans-serif; text-align: center; margin: 0; padding: 8px; }
      .name { font-size: 12px; font-weight: 600; margin-bottom: 2px; }
      .price { font-size: 12px; margin-top: 2px; }
    </style></head><body>
      <div class="name">${i??""}</div>
      ${o}
      ${n!=null?`<div class="price">₹ ${n}</div>`:""}
      <script>window.onload = function(){ window.print(); }<\/script>
    </body></html>`),e.document.close())}export{w as B,u as p};
