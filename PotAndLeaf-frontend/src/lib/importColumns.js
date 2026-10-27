/*
 * Columns for spreadsheet import / export of products, customers and suppliers.
 * The same keys are used for the template, the import and the export, so an
 * exported file can be edited in Excel and imported into another company.
 */

export const PRODUCT_COLUMNS = [
  { key: 'name', label: 'Name', required: true, example: 'Areca Palm (Indoor)', help: 'Product name, as shown on bills' },
  { key: 'sku', label: 'SKU', example: 'PLT-ARECA', help: 'Your item code. Leave empty to number it automatically' },
  { key: 'category', label: 'Category', required: true, example: 'Indoor Plants', help: 'Category name. A new one is added if it does not exist' },
  { key: 'unit', label: 'Unit', example: 'Nos', help: 'Unit name or short name (Nos, kg, bag). Added if new' },
  { key: 'gst_rate', label: 'GST %', example: '5', help: 'GST rate, e.g. 0, 5, 12, 18' },
  { key: 'cost_price', label: 'Cost price', required: true, example: '200', help: 'What you pay per piece' },
  { key: 'mrp', label: 'MRP', example: '450', help: 'Printed maximum price' },
  { key: 'retail_price', label: 'Retail price', example: '380', help: 'Selling price for normal customers' },
  { key: 'wholesale_price', label: 'Wholesale price', example: '330', help: 'Price for wholesale customers' },
  { key: 'dealer_price', label: 'Dealer price', example: '300', help: 'Price for dealers' },
  { key: 'reorder_level', label: 'Reorder level', example: '10', help: 'Warn when stock falls to this number' },
  { key: 'opening_stock', label: 'Opening stock', example: '25', help: 'Stock you already have. A batch and barcode is made for it' },
  { key: 'barcode', label: 'Barcode', example: '', help: 'Existing barcode on the item, if any' },
  { key: 'description', label: 'Description', example: '', help: 'Any note' },
];

export const CUSTOMER_COLUMNS = [
  { key: 'name', label: 'Name', required: true, example: 'City Garden Dealers', help: 'Customer or business name' },
  { key: 'type', label: 'Type', example: 'retail', help: 'retail, wholesale or dealer (empty = retail)' },
  { key: 'phone', label: 'Phone', example: '9876543210', help: 'Mobile number' },
  { key: 'whatsapp', label: 'WhatsApp', example: '9876543210', help: 'WhatsApp number if different' },
  { key: 'email', label: 'Email', example: '', help: 'Email address' },
  { key: 'gst_number', label: 'GST number', example: '', help: 'GSTIN for business customers' },
  { key: 'address_line1', label: 'Address', example: 'MG Road', help: 'Street / building' },
  { key: 'city', label: 'City', example: 'Kottayam', help: 'City or town' },
  { key: 'state', label: 'State', example: 'Kerala', help: 'State' },
  { key: 'pincode', label: 'PIN code', example: '686001', help: 'PIN code' },
  { key: 'credit_days', label: 'Credit days', example: '0', help: 'Days allowed to pay (0 = cash)' },
  { key: 'credit_limit', label: 'Credit limit', example: '0', help: 'Most they may owe (0 = no limit)' },
  { key: 'opening_balance', label: 'Opening balance', example: '0', help: 'Amount they already owe you' },
  { key: 'notes', label: 'Notes', example: '', help: 'Any note' },
];

export const SUPPLIER_COLUMNS = [
  { key: 'name', label: 'Name', required: true, example: 'Green Pots & Co', help: 'Supplier name' },
  { key: 'address', label: 'Address', required: true, example: 'Industrial Estate, Aluva', help: 'Full address' },
  { key: 'phone', label: 'Phone', example: '9123456780', help: 'Phone number' },
  { key: 'email', label: 'Email', example: '', help: 'Email address' },
  { key: 'gst_number', label: 'GST number', example: '', help: 'GSTIN' },
  { key: 'pan_number', label: 'PAN', example: '', help: 'PAN number' },
  { key: 'city', label: 'City', example: 'Aluva', help: 'City or town' },
  { key: 'state', label: 'State', example: 'Kerala', help: 'State' },
  { key: 'pincode', label: 'PIN code', example: '683101', help: 'PIN code' },
  { key: 'credit_days', label: 'Credit days', example: '30', help: 'Days you get to pay their bills' },
  { key: 'credit_limit', label: 'Credit limit', example: '0', help: 'Most you may owe them' },
  { key: 'opening_balance', label: 'Opening balance', example: '0', help: 'Amount you already owe them' },
  { key: 'bank_name', label: 'Bank', example: '', help: 'Bank name for payments' },
  { key: 'bank_account_no', label: 'Account no.', example: '', help: 'Bank account number' },
  { key: 'bank_ifsc', label: 'IFSC', example: '', help: 'IFSC code' },
  { key: 'notes', label: 'Notes', example: '', help: 'Any note' },
];

/** Export columns = import columns (+ a few read-only extras at the end). */
export const productExport = [
  ...PRODUCT_COLUMNS.map((c) => [c.key, (r) => (c.key === 'category' ? r.category?.name : c.key === 'unit' ? (r.unit?.short_name || r.unit?.name) : r[c.key])]),
  ['current_stock', (r) => r.current_stock], ['status', (r) => r.status],
];
export const customerExport = [
  ...CUSTOMER_COLUMNS.map((c) => [c.key, (r) => r[c.key]]),
  ['outstanding', (r) => r.outstanding], ['loyalty_points', (r) => r.loyalty_points], ['status', (r) => r.status],
];
export const supplierExport = [
  ...SUPPLIER_COLUMNS.map((c) => [c.key, (r) => r[c.key]]),
  ['outstanding', (r) => r.outstanding], ['status', (r) => r.status],
];
