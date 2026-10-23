<section class="container section institution-map-section"><x-marketing.heading eyebrow="CONNECTED BY CONTEXT" title="The right structure. The right boundaries.">A conceptual view of the planned institutional environment. Membership and permission features are not yet implemented.</x-marketing.heading><div class="institution-map">
@foreach([
['Institution', 'One explicit organisational context', '01'],
['Programmes & intakes', 'The academic structure within that institution', '02'],
['Classes & project groups', 'The setting for shared student work', '03'],
['Students & supervisors', 'People participate through defined membership and roles', '04']
] as [$title, $copy, $number])<div class="institution-map-node" data-motion="{{ $loop->odd ? 'left' : 'right' }}"><span>{{ $number }}</span><div><h3>{{ $title }}</h3><p>{{ $copy }}</p></div></div>@endforeach
<div class="personal-boundary" data-motion="scale"><strong>Independent personal workspace</strong><p>Private by default. Connected only through explicit sharing.</p></div>
</div></section>
