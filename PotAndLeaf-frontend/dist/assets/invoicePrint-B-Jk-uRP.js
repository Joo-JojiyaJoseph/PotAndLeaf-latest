const e=t=>String(t??"").replace(/[&<>"']/g,i=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"})[i]),o=t=>"₹"+Number(t||0).toLocaleString("en-IN",{minimumFractionDigits:2,maximumFractionDigits:2}),$=["","One","Two","Three","Four","Five","Six","Seven","Eight","Nine","Ten","Eleven","Twelve","Thirteen","Fourteen","Fifteen","Sixteen","Seventeen","Eighteen","Nineteen"],G=["","","Twenty","Thirty","Forty","Fifty","Sixty","Seventy","Eighty","Ninety"];function f(t){return t<20?$[t]:G[Math.floor(t/10)]+(t%10?" "+$[t%10]:"")}function R(t){const i=Math.floor(t/100),a=t%100;return(i?$[i]+" Hundred"+(a?" ":""):"")+(a?f(a):"")}function h(t){const i=Math.floor(Math.abs(t)),a=Math.round((Math.abs(t)-i)*100);if(i===0&&a===0)return"Zero Rupees";let d=i;const p=[],m=Math.floor(d/1e7);d%=1e7;const s=Math.floor(d/1e5);d%=1e5;const n=Math.floor(d/1e3);d%=1e3;const r=d;m&&p.push(R(m)+" Crore"),s&&p.push(f(s)+" Lakh"),n&&p.push(f(n)+" Thousand"),r&&p.push(R(r));let c=p.join(" ").trim()+" Rupees";return a&&(c+=" and "+f(a)+" Paise"),c+" Only"}function D(t){const i=t.company??{},a=t.is_interstate,d=(t.items??[]).map((n,r)=>{const c=(n.cgst_amount??0)+(n.sgst_amount??0)+(n.igst_amount??0);return`<tr>
      <td>${r+1}</td>
      <td class="l">${e(n.product_name)}${n.hsn_code?`<div class="hsn">HSN ${e(n.hsn_code)}</div>`:""}</td>
      <td>${n.qty}</td>
      <td class="r">${o(n.rate)}</td>
      <td class="r">${o(n.discount)}</td>
      <td class="r">${o(n.taxable_value)}</td>
      <td class="r">${o(c)}<div class="hsn">${n.gst_rate}%</div></td>
      <td class="r">${o(n.line_total)}</td>
    </tr>`}).join(""),p=a?`<tr><td>IGST</td><td class="r">${o(t.tax_total)}</td></tr>`:`<tr><td>CGST</td><td class="r">${o(t.tax_total/2)}</td></tr><tr><td>SGST</td><td class="r">${o(t.tax_total/2)}</td></tr>`,m=`<!doctype html><html><head><meta charset="utf-8"><title>Invoice ${e(t.sale_no)}</title>
  <style>
    * { box-sizing: border-box; }
    body { font-family: -apple-system, Segoe UI, Roboto, Arial, sans-serif; color: #1a1a1a; margin: 0; padding: 24px; font-size: 12px; }
    .inv { max-width: 800px; margin: 0 auto; }
    .top { display: flex; justify-content: space-between; border-bottom: 2px solid #2f5233; padding-bottom: 12px; }
    .co { font-size: 18px; font-weight: 700; color: #2f5233; }
    .muted { color: #666; font-size: 11px; line-height: 1.5; }
    .title { text-align: right; }
    .title h1 { margin: 0; font-size: 20px; letter-spacing: 1px; color: #2f5233; }
    .meta { margin-top: 4px; font-size: 11px; }
    .parties { display: flex; justify-content: space-between; margin: 16px 0; gap: 24px; }
    .box { flex: 1; }
    .lbl { text-transform: uppercase; font-size: 9px; letter-spacing: .6px; color: #999; margin-bottom: 3px; }
    table { width: 100%; border-collapse: collapse; margin-top: 8px; }
    th, td { padding: 6px 8px; text-align: center; border-bottom: 1px solid #eee; }
    th { background: #f4f7f4; font-size: 9px; text-transform: uppercase; letter-spacing: .5px; color: #555; }
    td.l { text-align: left; } td.r { text-align: right; }
    .hsn { color: #999; font-size: 9px; }
    .totals { margin-top: 12px; margin-left: auto; width: 260px; }
    .totals td { border: 0; padding: 3px 8px; }
    .grand td { border-top: 2px solid #2f5233; font-weight: 700; font-size: 14px; padding-top: 6px; }
    .words { margin-top: 12px; font-size: 11px; }
    .foot { margin-top: 28px; display: flex; justify-content: space-between; align-items: flex-end; }
    .sign { text-align: center; font-size: 11px; }
    .sign .line { margin-top: 34px; border-top: 1px solid #999; padding-top: 3px; }
    @media print { body { padding: 0; } .noprint { display: none; } }
    .btn { background: #2f5233; color: #fff; border: 0; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-size: 13px; }
  </style></head><body>
  <div class="noprint" style="max-width:800px;margin:0 auto 16px;text-align:right"><button class="btn" onclick="window.print()">Print / Save as PDF</button></div>
  <div class="inv">
    <div class="top">
      <div>
        <div class="co">${e(i.name||"Company")}</div>
        <div class="muted">${i.legal_name?e(i.legal_name)+"<br>":""}${i.address?e(i.address)+"<br>":""}${i.state?e(i.state)+(i.state_code?" ("+e(i.state_code)+")":"")+"<br>":""}${i.phone?"Ph: "+e(i.phone)+"  ":""}${i.gst_number?"<br><b>GSTIN: "+e(i.gst_number)+"</b>":""}</div>
      </div>
      <div class="title">
        <h1>TAX INVOICE</h1>
        <div class="meta"><b>${e(t.sale_no)}</b><br>${e(t.sale_date)}</div>
        <div class="meta">${e(t.payment_mode).toUpperCase()}${a?" · Inter-state":""}</div>
      </div>
    </div>

    <div class="parties">
      <div class="box"><div class="lbl">Bill to</div><div><b>${e(t.customer_name)}</b></div></div>
      <div class="box" style="text-align:right"><div class="lbl">Supply</div><div>${a?"Inter-state (IGST)":"Intra-state (CGST + SGST)"}</div></div>
    </div>

    <table>
      <thead><tr><th>#</th><th class="l">Item</th><th>Qty</th><th class="r">Rate</th><th class="r">Disc</th><th class="r">Taxable</th><th class="r">GST</th><th class="r">Total</th></tr></thead>
      <tbody>${d}</tbody>
    </table>

    <table class="totals">
      <tr><td>Subtotal</td><td class="r">${o(t.subtotal)}</td></tr>
      ${p}
      <tr><td>Round off</td><td class="r">${o(t.round_off)}</td></tr>
      ${Number(t.final_discount)>0?`<tr><td>Discount on final amount</td><td class="r">−${o(t.final_discount)}</td></tr>`:""}
      <tr class="grand"><td>Grand total</td><td class="r">${o(t.grand_total)}</td></tr>
    </table>

    <div class="words"><span class="lbl">Amount in words</span><br><b>${e(h(t.grand_total))}</b></div>

    <div class="foot">
      <div class="muted">This is a computer-generated invoice.</div>
      <div class="sign">For ${e(i.name||"Company")}<div class="line">Authorised Signatory</div></div>
    </div>
  </div>
  <script>window.onload = function(){ setTimeout(function(){ window.print(); }, 300); };<\/script>
  </body></html>`,s=window.open("","_blank","width=900,height=1000");s&&(s.document.open(),s.document.write(m),s.document.close())}function C(t){const i=t.company??{},a=t.supplier??{},d=t.is_interstate,p=(t.items??[]).map((r,c)=>{const x=(r.cgst_amount??0)+(r.sgst_amount??0)+(r.igst_amount??0);return`<tr>
      <td>${c+1}</td>
      <td class="l">${e(r.product_name)}${r.hsn_code?`<div class="hsn">HSN ${e(r.hsn_code)}</div>`:""}</td>
      <td>${r.qty}</td>
      <td class="r">${o(r.rate)}</td>
      <td class="r">${o(r.taxable_value)}</td>
      <td class="r">${o(x)}<div class="hsn">${r.gst_rate}%</div></td>
      <td class="r">${o(r.landed_unit_cost)}</td>
      <td class="r">${o(r.line_total)}</td>
    </tr>`}).join(""),m=d?`<tr><td>IGST</td><td class="r">${o(t.tax_total)}</td></tr>`:`<tr><td>CGST</td><td class="r">${o(t.tax_total/2)}</td></tr><tr><td>SGST</td><td class="r">${o(t.tax_total/2)}</td></tr>`,s=`<!doctype html><html><head><meta charset="utf-8"><title>GRN ${e(t.purchase_no)}</title>
  <style>
    * { box-sizing: border-box; }
    body { font-family: -apple-system, Segoe UI, Roboto, Arial, sans-serif; color: #1a1a1a; margin: 0; padding: 24px; font-size: 12px; }
    .inv { max-width: 800px; margin: 0 auto; }
    .top { display: flex; justify-content: space-between; border-bottom: 2px solid #2f5233; padding-bottom: 12px; }
    .co { font-size: 18px; font-weight: 700; color: #2f5233; }
    .muted { color: #666; font-size: 11px; line-height: 1.5; }
    .title { text-align: right; }
    .title h1 { margin: 0; font-size: 18px; letter-spacing: 1px; color: #2f5233; }
    .meta { margin-top: 4px; font-size: 11px; }
    .parties { display: flex; justify-content: space-between; margin: 16px 0; gap: 24px; }
    .box { flex: 1; }
    .lbl { text-transform: uppercase; font-size: 9px; letter-spacing: .6px; color: #999; margin-bottom: 3px; }
    table { width: 100%; border-collapse: collapse; margin-top: 8px; }
    th, td { padding: 6px 8px; text-align: center; border-bottom: 1px solid #eee; }
    th { background: #f4f7f4; font-size: 9px; text-transform: uppercase; letter-spacing: .5px; color: #555; }
    td.l { text-align: left; } td.r { text-align: right; }
    .hsn { color: #999; font-size: 9px; }
    .totals { margin-top: 12px; margin-left: auto; width: 280px; }
    .totals td { border: 0; padding: 3px 8px; }
    .grand td { border-top: 2px solid #2f5233; font-weight: 700; font-size: 14px; padding-top: 6px; }
    .words { margin-top: 12px; font-size: 11px; }
    .foot { margin-top: 28px; display: flex; justify-content: space-between; align-items: flex-end; }
    .sign { text-align: center; font-size: 11px; }
    .sign .line { margin-top: 34px; border-top: 1px solid #999; padding-top: 3px; }
    @media print { body { padding: 0; } .noprint { display: none; } }
    .btn { background: #2f5233; color: #fff; border: 0; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-size: 13px; }
  </style></head><body>
  <div class="noprint" style="max-width:800px;margin:0 auto 16px;text-align:right"><button class="btn" onclick="window.print()">Print / Save as PDF</button></div>
  <div class="inv">
    <div class="top">
      <div>
        <div class="co">${e(i.name||"Company")}</div>
        <div class="muted">${i.address?e(i.address)+"<br>":""}${i.gst_number?"<b>GSTIN: "+e(i.gst_number)+"</b>":""}</div>
      </div>
      <div class="title">
        <h1>GOODS RECEIPT NOTE</h1>
        <div class="meta"><b>${e(t.purchase_no)}</b><br>${e(t.purchase_date)}</div>
        ${t.invoice_no?`<div class="meta">Supplier inv: ${e(t.invoice_no)}</div>`:""}
        ${t.agent_code?`<div class="meta">Agent code: ${e(t.agent_code)}</div>`:""}
      </div>
    </div>

    <div class="parties">
      <div class="box"><div class="lbl">Received from</div><div><b>${e(a.name||"—")}</b>${a.supplier_code?`<div class="hsn">${e(a.supplier_code)}</div>`:""}</div></div>
      <div class="box" style="text-align:right"><div class="lbl">Supply</div><div>${d?"Inter-state (IGST)":"Intra-state (CGST + SGST)"}</div></div>
    </div>

    <table>
      <thead><tr><th>#</th><th class="l">Item</th><th>Qty</th><th class="r">Rate</th><th class="r">Taxable</th><th class="r">GST</th><th class="r">Landed/unit</th><th class="r">Total</th></tr></thead>
      <tbody>${p}</tbody>
    </table>

    <table class="totals">
      <tr><td>Subtotal</td><td class="r">${o(t.subtotal)}</td></tr>
      ${m}
      ${t.landed_cost_total?`<tr><td>Landed cost</td><td class="r">${o(t.landed_cost_total)}</td></tr>`:""}
      ${Number(t.final_discount)>0?`<tr><td>Discount on final amount</td><td class="r">−${o(t.final_discount)}</td></tr>`:""}
      <tr class="grand"><td>Grand total</td><td class="r">${o(t.grand_total)}</td></tr>
    </table>

    <div class="words"><span class="lbl">Amount in words</span><br><b>${e(h(t.grand_total))}</b></div>

    <div class="foot">
      <div class="muted">This is a computer-generated goods receipt note.</div>
      <div class="sign">Received by<div class="line">Signature</div></div>
    </div>
  </div>
  <script>window.onload = function(){ setTimeout(function(){ window.print(); }, 300); };<\/script>
  </body></html>`,n=window.open("","_blank","width=900,height=1000");n&&(n.document.open(),n.document.write(s),n.document.close())}function E(t,i){const a=t.company??{},d=i.cycles??1,p=(t.items??[]).map((n,r)=>{const c=n.outstanding_qty!=null?n.outstanding_qty:n.qty,x=c*(n.rate_per_cycle||0)*d;return`<tr>
      <td>${r+1}</td>
      <td class="l">${e(n.product_name)}</td>
      <td>${c}</td>
      <td class="r">${o(n.rate_per_cycle)}</td>
      <td>${d}</td>
      <td class="r">${o(x)}</td>
    </tr>`}).join(""),m=`<!doctype html><html><head><meta charset="utf-8"><title>Rental invoice ${e(i.invoice_no)}</title>
  <style>
    * { box-sizing: border-box; }
    body { font-family: -apple-system, Segoe UI, Roboto, Arial, sans-serif; color: #1a1a1a; margin: 0; padding: 24px; font-size: 12px; }
    .inv { max-width: 800px; margin: 0 auto; }
    .top { display: flex; justify-content: space-between; border-bottom: 2px solid #2f5233; padding-bottom: 12px; }
    .co { font-size: 18px; font-weight: 700; color: #2f5233; }
    .muted { color: #666; font-size: 11px; line-height: 1.5; }
    .title { text-align: right; }
    .title h1 { margin: 0; font-size: 18px; letter-spacing: 1px; color: #2f5233; }
    .meta { margin-top: 4px; font-size: 11px; }
    .parties { display: flex; justify-content: space-between; margin: 16px 0; gap: 24px; }
    .box { flex: 1; }
    .lbl { text-transform: uppercase; font-size: 9px; letter-spacing: .6px; color: #999; margin-bottom: 3px; }
    table { width: 100%; border-collapse: collapse; margin-top: 8px; }
    th, td { padding: 6px 8px; text-align: center; border-bottom: 1px solid #eee; }
    th { background: #f4f7f4; font-size: 9px; text-transform: uppercase; letter-spacing: .5px; color: #555; }
    td.l { text-align: left; } td.r { text-align: right; }
    .totals { margin-top: 12px; margin-left: auto; width: 260px; }
    .totals td { border: 0; padding: 3px 8px; }
    .grand td { border-top: 2px solid #2f5233; font-weight: 700; font-size: 14px; padding-top: 6px; }
    .words { margin-top: 12px; font-size: 11px; }
    .foot { margin-top: 28px; display: flex; justify-content: space-between; align-items: flex-end; }
    .sign { text-align: center; font-size: 11px; }
    .sign .line { margin-top: 34px; border-top: 1px solid #999; padding-top: 3px; }
    @media print { body { padding: 0; } .noprint { display: none; } }
    .btn { background: #2f5233; color: #fff; border: 0; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-size: 13px; }
  </style></head><body>
  <div class="noprint" style="max-width:800px;margin:0 auto 16px;text-align:right"><button class="btn" onclick="window.print()">Print / Save as PDF</button></div>
  <div class="inv">
    <div class="top">
      <div>
        <div class="co">${e(a.name||"Company")}</div>
        <div class="muted">${a.address?e(a.address)+"<br>":""}${a.gst_number?"<b>GSTIN: "+e(a.gst_number)+"</b>":""}</div>
      </div>
      <div class="title">
        <h1>RENTAL INVOICE</h1>
        <div class="meta"><b>${e(i.invoice_no)}</b></div>
        <div class="meta">Ref: ${e(t.rental_no)}</div>
      </div>
    </div>

    <div class="parties">
      <div class="box"><div class="lbl">Bill to</div><div><b>${e(t.customer_name)}</b></div></div>
      <div class="box" style="text-align:right"><div class="lbl">Period</div><div>${e(i.period_from)} – ${e(i.period_to)}</div><div class="muted">${d} × ${e(t.billing_cycle)}</div></div>
    </div>

    <table>
      <thead><tr><th>#</th><th class="l">Plant</th><th>Qty</th><th class="r">Rate / cycle</th><th>Cycles</th><th class="r">Amount</th></tr></thead>
      <tbody>${p}</tbody>
    </table>

    <table class="totals">
      <tr class="grand"><td>Total due</td><td class="r">${o(i.amount)}</td></tr>
    </table>

    <div class="words"><span class="lbl">Amount in words</span><br><b>${e(h(i.amount))}</b></div>

    <div class="foot">
      <div class="muted">This is a computer-generated rental invoice.</div>
      <div class="sign">For ${e(a.name||"Company")}<div class="line">Authorised Signatory</div></div>
    </div>
  </div>
  <script>window.onload = function(){ setTimeout(function(){ window.print(); }, 300); };<\/script>
  </body></html>`,s=window.open("","_blank","width=900,height=1000");s&&(s.document.open(),s.document.write(m),s.document.close())}function j(t){const i=t.company??{},a={daily:1,weekly:7,monthly:30}[t.billing_cycle]??30,d={daily:"day",weekly:"week",monthly:"month"}[t.billing_cycle]??"cycle",p=(t.items??[]).filter(l=>l.product_name),m=p.reduce((l,b)=>l+(Number(b.qty)||0)*(Number(b.rate_per_cycle)||0),0);let s=null;if(t.start_date&&t.expected_end_date&&t.expected_end_date>=t.start_date){const l=Math.round((new Date(t.expected_end_date)-new Date(t.start_date))/864e5)+1;s=Math.max(1,Math.ceil(l/a))}const n=Number(t.deposit)||0,r="",c=s?m*s:null,x=c!=null?c+n:null,I=p.map((l,b)=>{const u=Number(l.qty)||0,y=Number(l.rate_per_cycle)||0;return`<tr>
      <td>${b+1}</td>
      <td class="l">${e(l.product_name)}</td>
      <td>${u}</td>
      <td class="r">${o(y)}</td>
      <td class="r">${o(u*y)}</td>
      ${s?`<td class="r">${o(u*y*s)}</td>`:""}
    </tr>`}).join(""),_=new Date,v=l=>l.toLocaleDateString("en-IN",{day:"2-digit",month:"short",year:"numeric"}),w=Number(t.valid_days)||0,S=w?v(new Date(_.getTime()+w*864e5)):null,z=l=>l?v(new Date(l)):"—",T=String(t.terms||"").split(`
`).map(l=>l.trim()).filter(Boolean).map(l=>l.replace(/^\s*\d+[.)]\s*/,"")).map(l=>`<li>${e(l)}</li>`).join(""),N=`<!doctype html><html><head><meta charset="utf-8"><title>Rental estimate ${e(t.rental_no||"")}</title>
  <style>
    * { box-sizing: border-box; }
    body { font-family: -apple-system, Segoe UI, Roboto, Arial, sans-serif; color: #1a1a1a; margin: 0; padding: 24px; font-size: 12px; }
    .inv { max-width: 800px; margin: 0 auto; }
    .top { display: flex; justify-content: space-between; border-bottom: 2px solid #2f5233; padding-bottom: 12px; }
    .co { font-size: 18px; font-weight: 700; color: #2f5233; }
    .muted { color: #666; font-size: 11px; line-height: 1.5; }
    .title { text-align: right; }
    .title h1 { margin: 0; font-size: 18px; letter-spacing: 1px; color: #2f5233; }
    .meta { margin-top: 4px; font-size: 11px; }
    .parties { display: flex; justify-content: space-between; margin: 16px 0; gap: 24px; }
    .box { flex: 1; }
    .lbl { text-transform: uppercase; font-size: 9px; letter-spacing: .6px; color: #999; margin-bottom: 3px; }
    table { width: 100%; border-collapse: collapse; margin-top: 8px; }
    th, td { padding: 6px 8px; text-align: center; border-bottom: 1px solid #eee; }
    th { background: #f4f7f4; font-size: 9px; text-transform: uppercase; letter-spacing: .5px; color: #555; }
    td.l, th.l { text-align: left; } td.r, th.r { text-align: right; }
    .totals { margin-top: 12px; margin-left: auto; width: 320px; }
    .totals td { border: 0; padding: 3px 8px; text-align: left; } .totals td.r { text-align: right; }
    .grand td { border-top: 2px solid #2f5233; font-weight: 700; font-size: 14px; padding-top: 6px; }
    .words { margin-top: 12px; font-size: 11px; }
    .terms { margin-top: 20px; padding-top: 10px; border-top: 1px solid #ddd; }
    .terms h2 { margin: 0 0 6px; font-size: 12px; text-transform: uppercase; letter-spacing: .6px; color: #2f5233; }
    .terms ol { margin: 0; padding-left: 18px; font-size: 11px; line-height: 1.55; color: #333; }
    .terms li { margin-bottom: 3px; }
    .foot { margin-top: 30px; display: flex; justify-content: space-between; align-items: flex-end; gap: 24px; }
    .sign { text-align: center; font-size: 11px; flex: 1; }
    .sign .line { margin-top: 34px; border-top: 1px solid #999; padding-top: 3px; }
    @media print { body { padding: 0; } .noprint { display: none; } .terms { page-break-inside: avoid; } }
    .btn { background: #2f5233; color: #fff; border: 0; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-size: 13px; }
  </style></head><body>
  <div class="noprint" style="max-width:800px;margin:0 auto 16px;text-align:right"><button class="btn" onclick="window.print()">Print / Save as PDF</button></div>
  <div class="inv">
    <div class="top">
      <div>
        <div class="co">${e(i.legal_name||i.name||"Company")}</div>
        <div class="muted">${i.address?e(i.address)+"<br>":""}${i.phone?"Phone: "+e(i.phone)+"<br>":""}${i.gst_number?"<b>GSTIN: "+e(i.gst_number)+"</b>":""}</div>
      </div>
      <div class="title">
        <h1>RENTAL ESTIMATE</h1>
        <div class="meta">Date: <b>${v(_)}</b></div>
        ${t.rental_no?`<div class="meta">Ref: ${e(t.rental_no)}</div>`:""}
        ${S?`<div class="meta">Valid until: ${S}</div>`:""}
      </div>
    </div>

    <div class="parties">
      <div class="box"><div class="lbl">Estimate for</div><div><b>${e(t.customer_name||"—")}</b></div>${t.customer_phone?`<div class="muted">Phone: ${e(t.customer_phone)}</div>`:""}</div>
      <div class="box" style="text-align:right">
        <div class="lbl">Rental period</div>
        <div>${z(t.start_date)}${t.expected_end_date?" – "+z(t.expected_end_date):" onwards"}</div>
        <div class="muted">Rent per ${d}${s?` · ${s} ${d}${s>1?"s":""} expected`:""}</div>
      </div>
    </div>

    <table>
      <thead><tr><th>#</th><th class="l">Plant</th><th>Qty</th><th class="r">Rate / ${d}</th><th class="r">Rent per ${d}</th>${s?`<th class="r">For ${s} ${d}${s>1?"s":""}</th>`:""}</tr></thead>
      <tbody>${I}</tbody>
    </table>

    <table class="totals">
      <tr><td>Rent per ${d}</td><td class="r">${o(m)}</td></tr>
      ${c!=null?`<tr><td>Estimated rent (${s} ${d}${s>1?"s":""})</td><td class="r">${o(c)}</td></tr>`:""}
      ${n>0?`<tr><td>Security deposit (refundable)${r}</td><td class="r">${o(n)}</td></tr>`:""}
      ${x!=null?`<tr class="grand"><td>Estimated total</td><td class="r">${o(x)}</td></tr>`:`<tr class="grand"><td>Rent per ${d}</td><td class="r">${o(m)}</td></tr>`}
    </table>

    ${x!=null?`<div class="words"><span class="lbl">Amount in words</span><br><b>${e(h(x))}</b></div>`:'<div class="words muted">Rent runs for each day the plants are with you until they are returned.</div>'}

    ${T?`<div class="terms"><h2>Terms &amp; conditions</h2><ol>${T}</ol></div>`:""}

    <div class="foot">
      <div class="sign">Customer — accepted<div class="line">Signature &amp; date</div></div>
      <div class="sign">For ${e(i.legal_name||i.name||"Company")}<div class="line">Authorised Signatory</div></div>
    </div>
    <p class="muted" style="margin-top:14px;text-align:center">This is an estimate, not a tax invoice.</p>
  </div>
  <script>window.onload = function(){ setTimeout(function(){ window.print(); }, 300); };<\/script>
  </body></html>`,g=window.open("","_blank","width=900,height=1000");g&&(g.document.open(),g.document.write(N),g.document.close())}export{j as a,E as b,C as c,D as p};
