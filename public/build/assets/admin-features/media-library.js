const t = (key, replace = {}) => { let value = String(window.MC_I18N?.[key] ?? key); for (const [name, replacement] of Object.entries(replace)) value = value.replaceAll(`%${name}%`, String(replacement)); return value; };
(()=>{
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
