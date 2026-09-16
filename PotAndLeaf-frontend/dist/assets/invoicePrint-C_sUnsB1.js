const e=t=>String(t??"").replace(/[&<>"']/g,o=>({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"})[o]),i=t=>"₹"+Number(t||0).toLocaleString("en-IN",{minimumFractionDigits:2,maximumFractionDigits:2}),b=["","One","Two","Three","Four","Five","Six","Seven","Eight","Nine","Ten","Eleven","Twelve","Thirteen","Fourteen","Fifteen","Sixteen","Seventeen","Eighteen","Nineteen"],h=["","","Twenty","Thirty","Forty","Fifty","Sixty","Seventy","Eighty","Ninety"];function x(t){return t<20?b[t]:h[Math.floor(t/10)]+(t%10?" "+b[t%10]:"")}function f(t){const o=Math.floor(t/100),d=t%100;return(o?b[o]+" Hundred"+(d?" ":""):"")+(d?x(d):"")}function m(t){const o=Math.floor(Math.abs(t)),d=Math.round((Math.abs(t)-o)*100);if(o===0&&d===0)return"Zero Rupees";let a=o;const l=[],c=Math.floor(a/1e7);a%=1e7;const r=Math.floor(a/1e5);a%=1e5;const s=Math.floor(a/1e3);a%=1e3;const n=a;c&&l.push(f(c)+" Crore"),r&&l.push(x(r)+" Lakh"),s&&l.push(x(s)+" Thousand"),n&&l.push(f(n));let p=l.join(" ").trim()+" Rupees";return d&&(p+=" and "+x(d)+" Paise"),p+" Only"}function v(t){const o=t.company??{},d=t.is_interstate,a=(t.items??[]).map((s,n)=>{const p=(s.cgst_amount??0)+(s.sgst_amount??0)+(s.igst_amount??0);return`<tr>
      <td>${n+1}</td>
      <td class="l">${e(s.product_name)}${s.hsn_code?`<div class="hsn">HSN ${e(s.hsn_code)}</div>`:""}</td>
      <td>${s.qty}</td>
      <td class="r">${i(s.rate)}</td>
      <td class="r">${i(s.discount)}</td>
      <td class="r">${i(s.taxable_value)}</td>
      <td class="r">${i(p)}<div class="hsn">${s.gst_rate}%</div></td>
      <td class="r">${i(s.line_total)}</td>
    </tr>`}).join(""),l=d?`<tr><td>IGST</td><td class="r">${i(t.tax_total)}</td></tr>`:`<tr><td>CGST</td><td class="r">${i(t.tax_total/2)}</td></tr><tr><td>SGST</td><td class="r">${i(t.tax_total/2)}</td></tr>`,c=`<!doctype html><html><head><meta charset="utf-8"><title>Invoice ${e(t.sale_no)}</title>
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
        <div class="co">${e(o.name||"Company")}</div>
        <div class="muted">${o.legal_name?e(o.legal_name)+"<br>":""}${o.address?e(o.address)+"<br>":""}${o.state?e(o.state)+(o.state_code?" ("+e(o.state_code)+")":"")+"<br>":""}${o.phone?"Ph: "+e(o.phone)+"  ":""}${o.gst_number?"<br><b>GSTIN: "+e(o.gst_number)+"</b>":""}</div>
      </div>
      <div class="title">
        <h1>TAX INVOICE</h1>
        <div class="meta"><b>${e(t.sale_no)}</b><br>${e(t.sale_date)}</div>
        <div class="meta">${e(t.payment_mode).toUpperCase()}${d?" · Inter-state":""}</div>
      </div>
    </div>

    <div class="parties">
      <div class="box"><div class="lbl">Bill to</div><div><b>${e(t.customer_name)}</b></div></div>
      <div class="box" style="text-align:right"><div class="lbl">Supply</div><div>${d?"Inter-state (IGST)":"Intra-state (CGST + SGST)"}</div></div>
    </div>

    <table>
      <thead><tr><th>#</th><th class="l">Item</th><th>Qty</th><th class="r">Rate</th><th class="r">Disc</th><th class="r">Taxable</th><th class="r">GST</th><th class="r">Total</th></tr></thead>
      <tbody>${a}</tbody>
    </table>

    <table class="totals">
      <tr><td>Subtotal</td><td class="r">${i(t.subtotal)}</td></tr>
      ${l}
      <tr><td>Round off</td><td class="r">${i(t.round_off)}</td></tr>
      <tr class="grand"><td>Grand total</td><td class="r">${i(t.grand_total)}</td></tr>
    </table>

    <div class="words"><span class="lbl">Amount in words</span><br><b>${e(m(t.grand_total))}</b></div>

    <div class="foot">
      <div class="muted">This is a computer-generated invoice.</div>
      <div class="sign">For ${e(o.name||"Company")}<div class="line">Authorised Signatory</div></div>
    </div>
  </div>
  <script>window.onload = function(){ setTimeout(function(){ window.print(); }, 300); };<\/script>
  </body></html>`,r=window.open("","_blank","width=900,height=1000");r&&(r.document.open(),r.document.write(c),r.document.close())}function u(t){const o=t.company??{},d=t.supplier??{},a=t.is_interstate,l=(t.items??[]).map((n,p)=>{const g=(n.cgst_amount??0)+(n.sgst_amount??0)+(n.igst_amount??0);return`<tr>
      <td>${p+1}</td>
      <td class="l">${e(n.product_name)}${n.hsn_code?`<div class="hsn">HSN ${e(n.hsn_code)}</div>`:""}</td>
      <td>${n.qty}</td>
      <td class="r">${i(n.rate)}</td>
      <td class="r">${i(n.taxable_value)}</td>
      <td class="r">${i(g)}<div class="hsn">${n.gst_rate}%</div></td>
      <td class="r">${i(n.landed_unit_cost)}</td>
      <td class="r">${i(n.line_total)}</td>
    </tr>`}).join(""),c=a?`<tr><td>IGST</td><td class="r">${i(t.tax_total)}</td></tr>`:`<tr><td>CGST</td><td class="r">${i(t.tax_total/2)}</td></tr><tr><td>SGST</td><td class="r">${i(t.tax_total/2)}</td></tr>`,r=`<!doctype html><html><head><meta charset="utf-8"><title>GRN ${e(t.purchase_no)}</title>
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
        <div class="co">${e(o.name||"Company")}</div>
        <div class="muted">${o.address?e(o.address)+"<br>":""}${o.gst_number?"<b>GSTIN: "+e(o.gst_number)+"</b>":""}</div>
      </div>
      <div class="title">
        <h1>GOODS RECEIPT NOTE</h1>
        <div class="meta"><b>${e(t.purchase_no)}</b><br>${e(t.purchase_date)}</div>
        ${t.invoice_no?`<div class="meta">Supplier inv: ${e(t.invoice_no)}</div>`:""}
      </div>
    </div>

    <div class="parties">
      <div class="box"><div class="lbl">Received from</div><div><b>${e(d.name||"—")}</b>${d.supplier_code?`<div class="hsn">${e(d.supplier_code)}</div>`:""}</div></div>
      <div class="box" style="text-align:right"><div class="lbl">Supply</div><div>${a?"Inter-state (IGST)":"Intra-state (CGST + SGST)"}</div></div>
    </div>

    <table>
      <thead><tr><th>#</th><th class="l">Item</th><th>Qty</th><th class="r">Rate</th><th class="r">Taxable</th><th class="r">GST</th><th class="r">Landed/unit</th><th class="r">Total</th></tr></thead>
      <tbody>${l}</tbody>
    </table>

    <table class="totals">
      <tr><td>Subtotal</td><td class="r">${i(t.subtotal)}</td></tr>
      ${c}
      ${t.landed_cost_total?`<tr><td>Landed cost</td><td class="r">${i(t.landed_cost_total)}</td></tr>`:""}
      <tr class="grand"><td>Grand total</td><td class="r">${i(t.grand_total)}</td></tr>
    </table>

    <div class="words"><span class="lbl">Amount in words</span><br><b>${e(m(t.grand_total))}</b></div>

    <div class="foot">
      <div class="muted">This is a computer-generated goods receipt note.</div>
      <div class="sign">Received by<div class="line">Signature</div></div>
    </div>
  </div>
  <script>window.onload = function(){ setTimeout(function(){ window.print(); }, 300); };<\/script>
  </body></html>`,s=window.open("","_blank","width=900,height=1000");s&&(s.document.open(),s.document.write(r),s.document.close())}function y(t,o){const d=t.company??{},a=o.cycles??1,l=(t.items??[]).map((s,n)=>{const p=s.outstanding_qty!=null?s.outstanding_qty:s.qty,g=p*(s.rate_per_cycle||0)*a;return`<tr>
      <td>${n+1}</td>
      <td class="l">${e(s.product_name)}</td>
      <td>${p}</td>
      <td class="r">${i(s.rate_per_cycle)}</td>
      <td>${a}</td>
      <td class="r">${i(g)}</td>
    </tr>`}).join(""),c=`<!doctype html><html><head><meta charset="utf-8"><title>Rental invoice ${e(o.invoice_no)}</title>
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
        <div class="co">${e(d.name||"Company")}</div>
        <div class="muted">${d.address?e(d.address)+"<br>":""}${d.gst_number?"<b>GSTIN: "+e(d.gst_number)+"</b>":""}</div>
      </div>
      <div class="title">
        <h1>RENTAL INVOICE</h1>
        <div class="meta"><b>${e(o.invoice_no)}</b></div>
        <div class="meta">Ref: ${e(t.rental_no)}</div>
      </div>
    </div>

    <div class="parties">
      <div class="box"><div class="lbl">Bill to</div><div><b>${e(t.customer_name)}</b></div></div>
      <div class="box" style="text-align:right"><div class="lbl">Period</div><div>${e(o.period_from)} – ${e(o.period_to)}</div><div class="muted">${a} × ${e(t.billing_cycle)}</div></div>
    </div>

    <table>
      <thead><tr><th>#</th><th class="l">Plant</th><th>Qty</th><th class="r">Rate / cycle</th><th>Cycles</th><th class="r">Amount</th></tr></thead>
      <tbody>${l}</tbody>
    </table>

    <table class="totals">
      <tr class="grand"><td>Total due</td><td class="r">${i(o.amount)}</td></tr>
    </table>

    <div class="words"><span class="lbl">Amount in words</span><br><b>${e(m(o.amount))}</b></div>

    <div class="foot">
      <div class="muted">This is a computer-generated rental invoice.</div>
      <div class="sign">For ${e(d.name||"Company")}<div class="line">Authorised Signatory</div></div>
    </div>
  </div>
  <script>window.onload = function(){ setTimeout(function(){ window.print(); }, 300); };<\/script>
  </body></html>`,r=window.open("","_blank","width=900,height=1000");r&&(r.document.open(),r.document.write(c),r.document.close())}export{y as a,u as b,v as p};
