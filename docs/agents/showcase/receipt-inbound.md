# Warehouse inbound ("receive goods")

**Claim:** Staff can dictate a goods receipt in chat; Max resolves stock, supplier, and product, then queues an act with lines and price.

## Ask

> @…_bot Max, receive into North warehouse: Comfort towel set, 6 packs, total 6576. Supplier MarketPlus.

(Also tried without naming the stock — default staff stock applies when configured.)

## Done

1. `stocks.list` / default stock → North  
2. `suppliers.list` → MarketPlus  
3. `catalog.browse` → Comfort towel set  
4. One pending write: `stickers.receipts.create` with `lines` (`qty=6`, `price≈1096` so 6×price = 6576)  
5. Manager confirms → receipt act on the warehouse  

Group channel needs these ops in `group_ops` (`stickers.receipts.*`, `stickers.ins.*`, `stocks.list`, `suppliers.list`, `catalog.browse`). Without them Max cannot receive goods at all.

## Proof

- Runbooks: [assistant-groups.md](../assistant-groups.md), [stickers-qr.md](../stickers-qr.md), [stocks.md](../stocks.md)  
- Eval: `live.stickers_receipt_inbound` (`/assistant/eval`), fixture `stickers_receipt_inbound.json`  
- Maintainers: [Assistant groups / receipt](2f2bb67e-708e-4b9f-8931-21e1c97762a4)
