{{-- Barcode labels: one label laid out by App\Services\Labels\LabelLayout (every size in mm). Shared by the print page and the template preview. --}}
<style>
    .pos-label { position: absolute; box-sizing: border-box; overflow: hidden; display: flex; flex-direction: column; justify-content: center; background: #fff; color: #000; font-family: Arial, Helvetica, "Liberation Sans", sans-serif; }
    .pos-label * { box-sizing: border-box; }
    .pos-label .lbl-text { overflow: hidden; line-height: 1.15; flex: none; }
    .pos-label .lbl-line { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .pos-label .lbl-bold { font-weight: 700; }
    .pos-label .lbl-barcode { display: flex; flex-direction: column; align-items: center; overflow: hidden; flex: none; }
    .pos-label .lbl-barcode svg { display: block; flex: none; }
    .pos-label .lbl-hri { line-height: 1.15; white-space: nowrap; overflow: hidden; letter-spacing: .03em; }
    .pos-label .lbl-split { display: flex; justify-content: space-between; align-items: flex-end; gap: 1mm; line-height: 1.15; white-space: nowrap; overflow: hidden; flex: none; }
    .pos-label .lbl-split span { overflow: hidden; text-overflow: ellipsis; }
    .pos-label .lbl-split .lbl-right { font-weight: 700; flex: none; }
    @media screen { .pos-label.pos-label-outline { outline: 1px dashed #9ca3af; } }
</style>
