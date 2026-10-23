{!! '<?xml version="1.0" encoding="UTF-8"?>' !!}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
@foreach(['/', '/about', '/features', '/for-students', '/for-institutions', '/contact', '/apply'] as $path)
<url><loc>{{ rtrim(config('app.url'), '/').$path }}</loc></url>
@endforeach
</urlset>
