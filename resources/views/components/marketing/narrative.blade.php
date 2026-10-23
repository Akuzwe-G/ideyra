<section class="container section about-narrative"><x-marketing.heading eyebrow="WHY WE’RE BUILDING" title="A more connected chapter.">The problem gives us a reason to begin. The vision gives us a direction.</x-marketing.heading><ol class="narrative-timeline">
@foreach([
['The problem', 'Potential gets scattered.', 'Notes, chats and application tabs capture fragments. They rarely tell the whole story of how a student grows.'],
['The vision', 'Progress that connects.', 'Personal thinking, shared projects and future opportunities can support one another while ownership remains clear.'],
['The solution', 'A thoughtful foundation.', 'Today, account access and contact enquiries work. We are developing the workspace tools in deliberate, reviewable phases.'],
['The future', 'Growth you can carry forward.', 'Our roadmap connects ideas, collaboration and opportunities with a professional story students choose to share.']
] as [$label, $title, $copy])<li data-motion="{{ $loop->even ? 'right' : 'left' }}"><span class="timeline-index">{{ str_pad($loop->iteration, 2, '0', STR_PAD_LEFT) }}</span><div><p class="eyebrow">{{ $label }}</p><h3>{{ $title }}</h3><p>{{ $copy }}</p></div></li>@endforeach
</ol></section>
