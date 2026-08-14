(function(){
  'use strict';
  const root=document.querySelector('.koopo-pro-directory,.koopo-pro-profile');
  if(!root)return;
  document.querySelectorAll('[data-koopo-view]').forEach(button=>button.addEventListener('click',function(){
    document.querySelectorAll('[data-koopo-view]').forEach(item=>item.classList.toggle('is-active',item===button));
    root.classList.toggle('is-map-view',button.dataset.koopoView==='map');
    window.setTimeout(()=>{if(window.koopoProviderMap)window.koopoProviderMap.invalidateSize();},80);
  }));
  const galleryImages=[...document.querySelectorAll('[data-koopo-gallery] img')];
  const lightbox=document.querySelector('[data-koopo-lightbox]'); let activeGalleryIndex=0;
  function showGalleryImage(index){
    if(!lightbox||!galleryImages.length)return; activeGalleryIndex=(index+galleryImages.length)%galleryImages.length;
    const source=galleryImages[activeGalleryIndex]; const target=lightbox.querySelector('img'); target.src=source.dataset.fullSrc||source.src; target.alt=source.alt||'';
  }
  document.querySelectorAll('[data-gallery-index]').forEach(button=>button.addEventListener('click',()=>{showGalleryImage(Number(button.dataset.galleryIndex)||0);if(lightbox.showModal)lightbox.showModal();else lightbox.setAttribute('open','');}));
  if(lightbox){lightbox.querySelector('[data-lightbox-close]').addEventListener('click',()=>lightbox.close?lightbox.close():lightbox.removeAttribute('open'));lightbox.querySelector('[data-lightbox-prev]').addEventListener('click',()=>showGalleryImage(activeGalleryIndex-1));lightbox.querySelector('[data-lightbox-next]').addEventListener('click',()=>showGalleryImage(activeGalleryIndex+1));lightbox.addEventListener('click',event=>{if(event.target===lightbox)(lightbox.close?lightbox.close():lightbox.removeAttribute('open'));});}
  const reviewForm=document.querySelector('[data-provider-review-form]');
  if(reviewForm)reviewForm.addEventListener('submit',async event=>{
    event.preventDefault(); const status=reviewForm.querySelector('[data-review-status]'); const submit=reviewForm.querySelector('[type="submit"]'); const rating=Number(new FormData(reviewForm).get('rating')||0); const content=String(new FormData(reviewForm).get('content')||'').trim();
    status.textContent='Submitting review…';submit.disabled=true;
    try{const response=await fetch(`${String(window.KOOPO_PROVIDER&&KOOPO_PROVIDER.rest||'').replace(/\/$/,'')}/providers/${Number(reviewForm.dataset.providerId)}/reviews`,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-WP-Nonce':KOOPO_PROVIDER.nonce},body:JSON.stringify({rating,content})});const body=await response.json().catch(()=>({}));if(!response.ok)throw new Error(body.error||'Unable to submit review.');status.textContent=body.message||'Thanks for your review.';reviewForm.reset();}
    catch(error){status.textContent=error.message||'Unable to submit review.';}finally{submit.disabled=false;}
  });
  const mapNode=document.getElementById('koopo-provider-map');
  const dataNode=document.getElementById('koopo-provider-map-data');
  if(!mapNode||!dataNode||typeof window.L==='undefined')return;
  let points=[];try{points=JSON.parse(dataNode.textContent||'[]');}catch(error){points=[];}
  if(!points.length)return;
  const map=window.L.map(mapNode,{scrollWheelZoom:false,zoomControl:true});
  window.koopoProviderMap=map;
  window.L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'&copy; OpenStreetMap contributors'}).addTo(map);
  const bounds=[];const markers={};
  points.forEach(point=>{
    if(point.type==='service_area'){
      const area=window.L.circle([point.lat,point.lng],{radius:Number(point.radius||0),color:'#356453',weight:2,opacity:.75,fillColor:'#55b897',fillOpacity:.12,dashArray:'7 7'}).addTo(map);
      area.bindPopup('<div class="koopo-pro-popup"><div><a href="'+escapeHtml(point.url||'#')+'">'+escapeHtml(point.name)+'</a><small>'+escapeHtml(point.location||'Mobile service area')+'</small><strong>Mobile coverage is approximate</strong></div></div>');
      const areaBounds=area.getBounds();
      bounds.push([areaBounds.getSouthWest().lat,areaBounds.getSouthWest().lng],[areaBounds.getNorthEast().lat,areaBounds.getNorthEast().lng]);
      (markers[point.providerId]=markers[point.providerId]||[]).push(area);
      return;
    }
    const label=point.price||'View';
    const icon=window.L.divIcon({className:'koopo-pro-marker',html:'<span>'+escapeHtml(label)+'</span>',iconSize:null});
    const marker=window.L.marker([point.lat,point.lng],{icon}).addTo(map);
    const image=point.image?'<img src="'+escapeHtml(point.image)+'" alt="" />':'';
    marker.bindPopup('<div class="koopo-pro-popup">'+image+'<div><a href="'+escapeHtml(point.url||'#')+'">'+escapeHtml(point.name)+'</a><small>'+escapeHtml(point.location||'')+'</small><strong>'+escapeHtml(point.priceLabel||'')+'</strong></div></div>');
    bounds.push([point.lat,point.lng]);
    (markers[point.providerId]=markers[point.providerId]||[]).push(marker);
  });
  map.fitBounds(bounds,{padding:[42,42],maxZoom:14});
  document.querySelectorAll('[data-provider-id]').forEach(card=>{
    const id=card.dataset.providerId;
    card.addEventListener('mouseenter',()=>setActive(id,false));
    card.addEventListener('focusin',()=>setActive(id,false));
    card.addEventListener('click',event=>{if(event.target.closest('a'))return;setActive(id,true);});
  });
  function setActive(id,open){
    document.querySelectorAll('.koopo-pro-marker').forEach(node=>node.classList.remove('is-active'));
    (markers[id]||[]).forEach((marker,index)=>{const el=marker.getElement?marker.getElement():null;if(el)el.classList.add('is-active');if(open&&index===0){const center=marker.getLatLng?marker.getLatLng():marker.getBounds().getCenter();map.flyTo(center,Math.max(map.getZoom(),12),{duration:.55});marker.openPopup();}});
  }
  function escapeHtml(value){return String(value||'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));}
})();
