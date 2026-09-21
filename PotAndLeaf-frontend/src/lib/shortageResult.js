/** Parse POST /backorders and POST /sales/:id/backorder responses. */
export function parseShortageResponse(res) {
  const data = res?.data?.data ?? {};
  const transfers = data.transfer_requests ?? [];
  const backorder = data.backorder ?? (data.order_no ? data : null);

  return {
    message: res?.data?.message,
    backorder,
    transfers,
  };
}

export function shortageNavigatePath({ backorder, transfers }, fallbackCompanyId, canViewTransfers = true) {
  const cid = backorder?.company_id ?? fallbackCompanyId;
  if (backorder?.id) {
    return cid ? `/backorders/${backorder.id}?company_id=${cid}` : `/backorders/${backorder.id}`;
  }
  const transfer = transfers?.[0];
  if (canViewTransfers && transfer?.id) {
    const tcid = fallbackCompanyId || transfer.to_company_id;
    return tcid ? `/transfers/${transfer.id}?company_id=${tcid}` : `/transfers/${transfer.id}`;
  }
  return cid ? `/backorders?company_id=${cid}` : '/backorders';
}
