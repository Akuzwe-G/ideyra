@php($pdf=$pdf??false)
<style>
@page{size:A4;margin:18mm 17mm}
.cv-document{font-family:"{{ $pdf?'DejaVu Sans':'Montserrat' }}",sans-serif;font-size:10pt;line-height:1.55;color:#132447;background:#fff;margin:0 auto;padding:0;overflow-wrap:anywhere}
.cv-document *{box-sizing:border-box}.cv-document h1{font-size:22pt;line-height:1.2;margin:0 0 9mm}.cv-document h2{font-size:11pt;letter-spacing:.06em;text-transform:uppercase;border-bottom:1px solid #cdd6e5;padding-bottom:2mm;margin:7mm 0 3mm;page-break-after:avoid}.cv-document p{margin:0;white-space:pre-wrap;word-wrap:break-word}.cv-document a{color:#3759d7;text-decoration:underline;word-wrap:break-word}.cv-contact{font-size:10pt;white-space:pre-wrap;border-bottom:2px solid #132447;padding-bottom:5mm}.cv-section{page-break-inside:auto}.cv-modern .cv-contact{border-left:5px solid #3759d7;border-bottom:0;background:#eef2ff;padding:6mm}.cv-modern h2{color:#3759d7;border-color:#3759d7}.cv-technical .cv-contact{border-top:6px solid #132447;border-bottom:1px solid #16b8c6;padding-top:5mm}.cv-technical h2{font-family:"{{ $pdf?'DejaVu Sans Mono':'Montserrat' }}",monospace;color:#176570;border-bottom:2px solid #16b8c6}
@media screen{.cv-document{padding:18mm 17mm;width:794px;max-width:none;min-height:1123px;box-shadow:0 4px 30px #13244715}.cv-document h2{font-size:14px}}
@media print{html,body{margin:0;padding:0}.cv-document{zoom:1!important;box-shadow:none;padding:0!important;width:auto!important;min-height:0!important}.cv-preview-toolbar{display:none!important}.cv-paper-viewport{overflow:visible!important}}
</style>
@php($contactLines=explode("\n",str_replace("\r\n","\n",$payload['content']['contact']??'')))
@php($contactName=array_shift($contactLines))
<article class="cv-document cv-{{ $payload['template'] }}"><header class="cv-contact">@if($contactName)<h1>{{ $contactName }}</h1>@endif<p><x-career.text :text="implode(PHP_EOL,$contactLines)" /></p></header>
@foreach($payload['sections'] as $section)@if(trim($payload['content'][$section]??'')!=='')<section class="cv-section"><h2>{{ config('career.sections.'.$section) }}</h2><p><x-career.text :text="$payload['content'][$section]" /></p></section>@endif @endforeach</article>
