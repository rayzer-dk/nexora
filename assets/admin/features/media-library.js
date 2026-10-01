const t = (key, replace = {}) => { let value = String(window.MC_I18N?.[key] ?? key); for (const [name, replacement] of Object.entries(replace)) value = value.replaceAll(`%${name}%`, String(replacement)); return value; };
(()=>{
 // Files of one picture (source, master, made sizes, sizes still to be made), loaded when the panel is opened.
 const grid=document.querySelector('[data-media-labels]'); let labels={}; try{labels=JSON.parse(grid?.dataset.mediaLabels||'{}')}catch(_e){}
 const kb=b=>b>=1048576?(b/1048576).toFixed(1)+' MB':Math.max(1,Math.round(b/1024))+' KB';
 document.querySelectorAll('[data-media-files]').forEach(box=>box.addEventListener('toggle',async()=>{
   const body=box.querySelector('[data-media-files-body]'); if(!box.open||!body||body.dataset.loaded)return;
   body.textContent=labels.loading||''; body.dataset.loaded='1';
   try{
     const r=await fetch(box.dataset.url,{headers:{Accept:'application/json'},credentials:'same-origin'}); const d=await r.json(); body.textContent='';
     if(!d.files?.length){body.textContent=labels.none||'';return;}
     const table=document.createElement('table'); table.className='media-files__table';
     d.files.forEach(f=>{
       const tr=table.insertRow(); const name=f.role==='source'?labels.source:f.role==='master'?labels.master:(labels.presets?.[f.preset]||f.preset);
       const c1=tr.insertCell(); const a=document.createElement('a'); a.href=f.url; a.target='_blank'; a.rel='noopener'; a.textContent=name; c1.append(a);
       if(f.role==='size'&&!f.current){const em=document.createElement('em'); em.textContent=' ('+(labels.outdated||'')+')'; c1.append(em); tr.classList.add('is-outdated');}
       tr.insertCell().textContent=f.format; tr.insertCell().textContent=f.width?f.width+'×'+f.height:''; tr.insertCell().textContent=kb(f.bytes);
     });
     (d.pending||[]).forEach(p=>{const tr=table.insertRow(); tr.classList.add('is-pending'); const c=tr.insertCell(); c.colSpan=4; c.textContent=(labels.presets?.[p.preset]||p.preset)+' '+p.width+' px — '+(labels.pending||'');});
     const foot=table.insertRow(); const fc=foot.insertCell(); fc.colSpan=3; fc.textContent=labels.total||''; foot.insertCell().textContent=kb(d.bytes||0); foot.className='is-total';
     body.append(table);
   }catch(_e){body.textContent=labels.none||'';}
 }));
 document.querySelectorAll('[data-media-edit]').forEach(b=>b.addEventListener('click',()=>{const e=document.querySelector('[data-media-editor="'+b.dataset.mediaEdit+'"]');if(e)e.hidden=!e.hidden;}));
 const form=document.querySelector('[data-media-drop]'), zone=form?.querySelector('.media-dropzone'), input=form?.querySelector('input[type=file]');
 ['dragenter','dragover'].forEach(n=>zone?.addEventListener(n,e=>{e.preventDefault();zone.classList.add('is-drag')}));
 ['dragleave','drop'].forEach(n=>zone?.addEventListener(n,e=>{e.preventDefault();zone.classList.remove('is-drag')}));
 zone?.addEventListener('drop',e=>{if(input&&e.dataTransfer?.files)input.files=e.dataTransfer.files});
 if(!form)return;
 form.addEventListener('submit',e=>{
   if(!window.XMLHttpRequest||!input?.files?.length)return;
   e.preventDefault();
   const progress=form.querySelector('[data-media-upload-progress]'), status=form.querySelector('[data-media-upload-status]');
   const xhr=new XMLHttpRequest(); xhr.open('POST',form.action); xhr.setRequestHeader('X-Requested-With','XMLHttpRequest');
   if(progress){progress.hidden=false;progress.value=0} if(status)status.textContent=t('js_loading');
   xhr.upload.addEventListener('progress',ev=>{if(ev.lengthComputable&&progress)progress.value=Math.round((ev.loaded/ev.total)*100)});
   xhr.addEventListener('load',()=>{if(xhr.status>=200&&xhr.status<400){window.location.reload();return;}if(status)status.textContent=t('js_upload_error');});
   xhr.addEventListener('error',()=>{if(status)status.textContent=t('js_network_interrupted');});
   xhr.send(new FormData(form));
 });
})();

(()=>{
 const checks=()=>Array.from(document.querySelectorAll('[data-media-check]'));
 const all=document.querySelector('[data-media-check-all]'), label=document.querySelector('[data-media-selected]'), btns=document.querySelectorAll('[data-media-bulk-btn]');
 const sync=()=>{const n=checks().filter(c=>c.checked).length;if(label)label.textContent=n?t('js_media_selected',{count:n}):t('js_media_none_selected');btns.forEach(b=>{b.disabled=!n});if(all){all.checked=n>0&&n===checks().length;all.indeterminate=n>0&&n<checks().length;}document.querySelectorAll('.media-card').forEach(c=>c.classList.toggle('is-selected',Boolean(c.querySelector('[data-media-check]:checked'))));};
 all?.addEventListener('change',()=>{checks().forEach(c=>{c.checked=all.checked});sync();});
 document.addEventListener('change',e=>{if(e.target.matches?.('[data-media-check]'))sync();});
 const file=document.querySelector('[data-media-autosubmit]');
 file?.addEventListener('change',()=>{if(file.files?.length)file.form?.requestSubmit();});
 sync();
})();
