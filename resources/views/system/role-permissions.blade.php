@php($permissionDefinitions = $permissions->keyBy('name'))
<p class="role-permission-count">{{ count($role->permissions) }} assigned permissions</p>
<div class="role-permission-groups">
@forelse(collect($role->permissions)->groupBy(fn ($key) => $permissionDefinitions->get($key)?->group ?? 'Other capabilities') as $group => $keys)
<section class="role-permission-group" aria-label="{{ $role->name }}: {{ $group }}">
<h3>{{ $group }} <span>{{ $keys->count() }}</span></h3>
<ul class="role-permission-list">
@foreach($keys as $key)<li><x-ui.icon name="check" /><code>{{ $key }}</code></li>@endforeach
</ul>
</section>
@empty<p>No permissions assigned.</p>@endforelse
</div>
