<x-layouts.public title="For Students" description="Follow the planned Ideyra student journey: capture ideas, develop projects, collaborate, explore opportunities and showcase your achievements.">
<x-marketing.intro eyebrow="FOR STUDENTS" title="Your ambition. A clearer way forward.">You don’t need a finished plan to begin. Start with curiosity, give it some structure and make space for what you could become.</x-marketing.intro>
<section class="container section-topless"><ol class="student-journey">
@foreach([
['Capture an idea.', 'A question after class. A problem on campus. A possibility you can’t stop thinking about. The planned Idea Vault will give those first sparks a home.'],
['Develop it into a project.', 'Project Studio will help turn a promising thought into a direction, with steps and outcomes you can work towards.'],
['Collaborate with others.', 'Future groups, tasks and deliverables will make shared responsibility clearer, whether you’re working independently or on an academic project.'],
['Discover and track opportunities.', 'Explore future hackathons, internships, scholarships and competitions, and keep application progress close to your work.'],
['Build a professional profile.', 'Bring your experience, skills and achievements into a professional story that can develop as you do.'],
['Showcase what you’ve achieved.', 'Planned CV and portfolio tools will help you choose what to share and make your growth visible beyond the project.']
] as [$title, $copy])<li><span class="journey-index">{{ str_pad($loop->iteration, 2, '0', STR_PAD_LEFT) }}</span><div><h2>{{ $title }}</h2><p>{{ $copy }}</p><x-ui.badge>Planned</x-ui.badge></div></li>@endforeach
</ol></section>
<section class="institution-section"><div class="container dual-use"><div><p class="eyebrow">ON YOUR OWN TERMS</p><h2>Independent work<br>stays yours.</h2><p>Your personal workspace will be independent of any institution. Personal content will be private by default, and sharing will require an explicit decision.</p></div><div><p class="eyebrow">PART OF SOMETHING BIGGER</p><h2>One person.<br>More than one community.</h2><p>The architecture is designed for participation in multiple institutions. Academic projects will have their own membership and access boundaries, without opening your personal work to everyone.</p></div></div></section>
<x-marketing.cta title="Begin with possibility.">Create your account now. The student workspace tools described here are still in development.</x-marketing.cta>
</x-layouts.public>
