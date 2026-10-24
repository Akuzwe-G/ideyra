<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow"><title>{{ $payload['title'] }} — CV preview</title>
@vite(['resources/css/app.css','resources/js/app.js'])
<style>
:root{ @foreach(['navy','blue','teal','sky','white','slate','amber'] as $token) --brand-{{ $token }}:{{ config('ideyra.brand.'.$token) }}; @endforeach }
html,body{margin:0;min-width:0;max-width:100%}.cv-preview-toolbar{display:flex;flex-wrap:wrap;align-items:center;gap:.6rem;padding:12px 20px;background:#132447;color:#fff}.cv-preview-toolbar strong{font-size:.85rem;flex:1 1 180px;overflow-wrap:anywhere}.cv-preview-toolbar .button{font-size:.75rem;padding:.55rem .7rem}.cv-paper-viewport{max-width:100%;overflow:auto}.cv-paper-viewport>.cv-document{margin:0 auto}
</style></head><body style="background:#edf1f7">
<div class="cv-preview-toolbar"><strong>{{ $payload['title'] }}</strong><button class="button button-secondary" type="button" data-cv-paper-size aria-pressed="false">Actual size (100%)</button><button class="button button-secondary" type="button" onclick="window.print()">Print preview</button></div>
<div class="cv-paper-viewport" data-cv-paper-viewport>@include('career.cv-document',['pdf'=>false])</div>
</body></html>
