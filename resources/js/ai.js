document.querySelectorAll('[data-ai-coach]').forEach(root=>{
    root.querySelectorAll('[data-ai-prompt]').forEach(button=>button.addEventListener('click',()=>{
        const input=root.querySelector('[name="message"]'); input.value=button.dataset.aiPrompt; input.focus();
    }));
    root.querySelectorAll('[data-ai-copy]').forEach(button=>button.addEventListener('click',async()=>{
        try { await navigator.clipboard.writeText(button.closest('.ai-message').querySelector('[data-ai-response]').innerText); button.textContent='Copied'; }
        catch { button.textContent='Select the response text to copy'; }
    }));
    const form=root.querySelector('[data-ai-form]');
    if(!form) return;
    const stop=form.querySelector('[data-ai-stop]');
    const feedback=form.querySelector('[data-ai-error]');
    let controller;
    stop.addEventListener('click',()=>controller?.abort());
    form.addEventListener('submit',async event=>{
        if(event.defaultPrevented) return;
        event.preventDefault();
        const button=event.submitter;
        if(controller) return;
        controller=new AbortController();
        form.setAttribute('aria-busy','true');
        form.querySelector('[data-ai-progress]').hidden=false;
        feedback.hidden=true; stop.hidden=false;
        if(button) button.disabled=true;
        try {
            const response=await fetch(form.action,{method:'POST',body:new FormData(form),headers:{Accept:'application/json'},credentials:'same-origin',signal:controller.signal});
            const data=await response.json();
            if(response.ok && data.redirect) {
                const target=new URL(data.redirect,location.href);
                if(target.origin!==location.origin) throw new Error('invalid_redirect');
                location.assign(target.href);
                return;
            }
            feedback.textContent=data.errors?Object.values(data.errors).flat().join(' '):'AI could not respond. Please try again later.';
            feedback.hidden=false;
        } catch(error) {
            feedback.textContent=error.name==='AbortError'?'Stopped waiting. A request already sent may still finish and count toward your quota. Refresh history before retrying.':'AI could not respond. Your question remains here so you can retry.';
            feedback.hidden=false;
        } finally {
            controller=null; stop.hidden=true;
            form.removeAttribute('aria-busy'); form.querySelector('[data-ai-progress]').hidden=true;
            if(button) { button.disabled=false;button.classList.remove('is-loading');button.removeAttribute('aria-disabled'); }
        }
    });
});

