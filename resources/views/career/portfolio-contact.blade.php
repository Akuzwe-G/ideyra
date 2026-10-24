@if($portfolio->contact_form_enabled)
<section class="portfolio-section"><h2>Get in touch</h2>
@if($preview)<p>The contact form will be available after you publish with this option enabled. Messages are saved privately in Ideyra; no external email delivery is claimed.</p>
@else
@if(session('status'))<p role="status">{{ session('status') }}</p>@endif
@if($errors->any())<div role="alert"><h3>Please check your message</h3><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<form class="innovation-form portfolio-card" method="POST" action="{{ route('portfolio.contact',$portfolio->slug) }}">@csrf
<input type="hidden" name="form_token" value="{{ \Illuminate\Support\Facades\Crypt::encryptString(json_encode(['portfolio_id'=>$portfolio->id,'issued_at'=>now()->timestamp,'nonce'=>\Illuminate\Support\Str::random(32)])) }}">
<div class="career-contact-trap" aria-hidden="true"><label>Company website<input name="company" tabindex="-1" autocomplete="off"></label></div>
<label>Your name<input name="sender_name" required maxlength="150" autocomplete="name" value="{{ old('sender_name') }}"></label>
<label>Your email<input type="email" name="sender_email" required maxlength="254" autocomplete="email" value="{{ old('sender_email') }}"></label>
<label>Professional enquiry<textarea name="message" required minlength="20" maxlength="3000" rows="5">{{ old('message') }}</textarea></label>
<label class="check-row"><input type="checkbox" name="consent" value="1" required>I agree to share my name, email and message with this portfolio owner through their private Ideyra inbox.</label>
<p>Please use this form for relevant professional enquiries. Messages stay in Ideyra; external email delivery is not enabled.</p><button class="button">Send professional enquiry</button></form>
@endif</section>@endif
