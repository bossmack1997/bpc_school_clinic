/* Public static assets only. No authenticated HTML, API data or uploaded files. */
const ROOT = new URL('./', self.location.href);
const PREFIX = 'bpc-clinic-' + encodeURIComponent(ROOT.pathname) + '-static-';
const CACHE_NAME = PREFIX + 'v2';
const OFFLINE_URL = new URL('offline.html', ROOT).href;
const STATIC_ASSETS = ['offline.html','manifest.json','assets/icons/icon-180.png','assets/icons/icon-192.png','assets/icons/icon-512.png','assets/images/bpc_logo.png','assets/css/bootstrap.min.css','assets/css/bootstrap-icons.min.css','assets/css/style.css','assets/js/jquery.min.js','assets/js/bootstrap.bundle.min.js'];
function publicAsset(url) {
  if(url.origin !== ROOT.origin || !url.pathname.startsWith(ROOT.pathname)) return false;
  const path=url.pathname.slice(ROOT.pathname.length);
  return /^(?:assets\/(?:css|js|fonts|icons)\/[a-zA-Z0-9_./-]+\.(?:css|js|woff2?|ttf|png|svg)|assets\/images\/(?:bpc_logo\.png|default-avatar\.svg)|manifest\.json|offline\.html)$/.test(path);
}
self.addEventListener('install',event=>event.waitUntil((async()=>{
 const cache=await caches.open(CACHE_NAME);
 // The offline document is required; optional assets must not break installation.
 await cache.add(OFFLINE_URL);
 await Promise.allSettled(STATIC_ASSETS.filter(path=>path!=='offline.html').map(path=>cache.add(new URL(path,ROOT).href)));
 await self.skipWaiting();
})()));
self.addEventListener('activate',event=>event.waitUntil((async()=>{
 for(const name of await caches.keys())if((name.startsWith(PREFIX)&&name!==CACHE_NAME)||name==='bpc-clinic-static-v1')await caches.delete(name);
 await self.clients.claim();
})()));
self.addEventListener('fetch',event=>{
 const request=event.request,url=new URL(request.url);
 if(request.method!=='GET'||url.origin!==ROOT.origin||!url.pathname.startsWith(ROOT.pathname))return;
 if(request.mode==='navigate'){
   event.respondWith(fetch(request,{cache:'no-store'}).catch(async()=> (await (await caches.open(CACHE_NAME)).match(OFFLINE_URL)) || new Response('You are offline. Reconnect to access clinic records.',{status:503,headers:{'Content-Type':'text/plain'}})));
   return;
 }
 if(!publicAsset(url))return;
 event.respondWith((async()=>{
   const cache=await caches.open(CACHE_NAME);
   try{
     const response=await fetch(request);
     if(response.ok && !response.redirected && !/private|no-store/i.test(response.headers.get('cache-control')||''))await cache.put(request,response.clone());
     return response;
   }catch{
     return (await cache.match(request)) || new Response('Offline asset unavailable',{status:503});
   }
 })());
});
