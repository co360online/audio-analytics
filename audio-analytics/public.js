(function(){
  function getSessionId(){
    const KEY = 'co360_audio_session_id';
    let id = localStorage.getItem(KEY);
    if (!id) {
      id = (crypto && crypto.randomUUID) ? crypto.randomUUID() :
        'xxxxxxxxyxxx'.replace(/[xy]/g, c => {
          const r = Math.random()*16|0, v = c === 'x' ? r : (r&0x3|0x8);
          return v.toString(16);
        });
      localStorage.setItem(KEY, id);
    }
    return id;
  }

  function postEvent(payload){
    const data = new FormData();
    data.append('action', 'co360_audio_event');
    data.append('nonce', CO360AUDIO.nonce);
    for (const k in payload) data.append(k, payload[k]);

    if (navigator.sendBeacon && payload.event !== 'meta') {
      const url = CO360AUDIO.ajax_url;
      return navigator.sendBeacon(url, data);
    } else {
      return fetch(CO360AUDIO.ajax_url, {
        method: 'POST',
        body: data,
        credentials: 'same-origin'
      }).catch(()=>{});
    }
  }

  const sessionId = getSessionId();

  function bindAudio(el){
    if (el._co360Bound) return;
    el._co360Bound = true;

    const audioId = el.getAttribute('data-audio-id');
    if (!audioId) return;

    const title = el.getAttribute('data-audio-title') || audioId;

    // Enviar metadatos (título + duración) al cargar metadata
    el.addEventListener('loadedmetadata', ()=> {
      const d = Math.floor(el.duration || 0);
      postEvent({
        audio_id: audioId,
        event: 'meta',
        duration: d,
        title: title,
        session_id: sessionId
      });
    });

    let lastTime = 0;
    let playedAccum = 0;
    let lastState = 'pause';
    let lastSentAt = 0;

    function sendDelta(force){
      const now = Date.now();
      const delta = Math.floor(playedAccum);
      if (delta > 0 && (force || now - lastSentAt > 4000)) {
        postEvent({
          audio_id: audioId,
          event: 'progress',
          delta: delta,
          session_id: sessionId
        });
        playedAccum = 0;
        lastSentAt = now;
      }
    }

    el.addEventListener('play', ()=> {
      postEvent({
        audio_id: audioId,
        event: 'play',
        delta: 0,
        session_id: sessionId
      });
      lastState = 'play';
      lastTime = el.currentTime || 0;
    });

    el.addEventListener('pause', ()=> {
      if (lastState === 'play') {
        const nowT = el.currentTime || 0;
        const diff = Math.max(0, nowT - lastTime);
        playedAccum += diff;
        sendDelta(true);
      }
      lastState = 'pause';
      lastTime = el.currentTime || 0;
    });

    el.addEventListener('seeking', ()=> {
      if (lastState === 'play') {
        const nowT = el.currentTime || 0;
        const diff = Math.max(0, nowT - lastTime);
        playedAccum += diff;
        sendDelta(true);
      }
      lastTime = el.currentTime || 0;
    });

    el.addEventListener('timeupdate', ()=> {
      if (lastState === 'play') {
        const nowT = el.currentTime || 0;
        const diff = Math.max(0, nowT - lastTime);
        if (diff > 0 && diff < 5) {
          playedAccum += diff;
        }
        lastTime = nowT;
        sendDelta(false);
      }
    });

    el.addEventListener('ended', ()=> {
      const nowT = el.currentTime || 0;
      const diff = Math.max(0, nowT - lastTime);
      playedAccum += diff;
      sendDelta(true);
      lastState = 'pause';
      lastTime = 0;
    });

    document.addEventListener('visibilitychange', ()=>{
      if (document.hidden) sendDelta(true);
    });
    window.addEventListener('beforeunload', ()=>{
      sendDelta(true);
    });
  }

  function init(){
    document.querySelectorAll('audio[data-audio-id]').forEach(bindAudio);
    const obs = new MutationObserver(muts=>{
      muts.forEach(m=>{
        m.addedNodes && m.addedNodes.forEach(n=>{
          if (n.nodeType === 1) {
            if (n.matches && n.matches('audio[data-audio-id]')) bindAudio(n);
            n.querySelectorAll && n.querySelectorAll('audio[data-audio-id]').forEach(bindAudio);
          }
        });
      });
    });
    obs.observe(document.documentElement, {childList:true, subtree:true});
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
