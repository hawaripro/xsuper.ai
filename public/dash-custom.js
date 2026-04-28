(function(){
  document.title="UltrAI Dashboard";

  function setFavicon(){
    var existing=document.querySelectorAll("link[rel*='icon']");
    existing.forEach(function(el){el.remove();});
    var lk=document.createElement("link");
    lk.rel="icon";
    lk.type="image/svg+xml";
    lk.href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Ccircle cx='50' cy='50' r='45' fill='%23ef4444'/%3E%3C/svg%3E";
    document.head.appendChild(lk);
  }
  setFavicon();

  var style=document.createElement("style");
  style.textContent="#root{opacity:0;transition:opacity 0.2s ease}#root.ultrai-ready{opacity:1}";
  document.head.appendChild(style);

  function run(){
    if(document.title.indexOf("UltrAI")!==0) document.title="UltrAI Dashboard";
    setFavicon();

    // Rebrand all enowx text
    var w=document.createTreeWalker(document.body,NodeFilter.SHOW_TEXT,null,false);
    while(w.nextNode()){
      var v=w.currentNode.nodeValue;
      if(v && v.match(/enowx/i)){
        w.currentNode.nodeValue=v.replace(/enowxai/gi,"UltrAI").replace(/enowx labs/gi,"UltrAI").replace(/enowx/gi,"UltrAI");
      }
      if(v && v.trim()==="Chat UI"){
        w.currentNode.nodeValue="UltrAI Chat";
      }
    }

    // Fix ALL Chat links (sidebar + mobile sheet + anywhere)
    document.querySelectorAll("a").forEach(function(el){
      var href=el.getAttribute("href")||"";
      if(href.indexOf("1430/chat")!==-1 || href.indexOf(":1430")!==-1){
        el.href="https://ultrai.id/chat";
        el.target="_blank";
      }
    });

    // Find ALL containers that have Chat link (sidebar, mobile sheet, etc)
    document.querySelectorAll("a").forEach(function(a){
      var h=a.getAttribute("href")||"";
      if(h!=="https://ultrai.id/chat") return;

      // Check if this container already has UltrAI Panel
      var parent=a.parentElement;
      if(!parent) return;
      var alreadyHas=parent.querySelector("[data-ultrai-link]");
      if(alreadyHas) return;

      // Clone this link to create UltrAI Panel
      var n=a.cloneNode(true);
      n.setAttribute("data-ultrai-link","true");
      n.href="https://ultrai.id/dashboard";
      n.target="_blank";
      n.classList.remove("active");

      // Replace icon with dashboard grid icon (same style as original)
      var svgs=n.querySelectorAll("svg");
      if(svgs.length>0){
        var s=svgs[0];
        s.setAttribute("viewBox","0 0 256 256");
        s.setAttribute("fill","currentColor");
        s.innerHTML="<path d=\"M216,40H40A16,16,0,0,0,24,56V200a16,16,0,0,0,16,16H216a16,16,0,0,0,16-16V56A16,16,0,0,0,216,40ZM120,176H56V136h64Zm0-56H56V80h64Zm80,56H136V136h64Zm0-56H136V80h64Z\"/>";
      }

      // Replace text
      var tw=document.createTreeWalker(n,NodeFilter.SHOW_TEXT,null,false);
      while(tw.nextNode()){
        var txt=tw.currentNode.nodeValue.trim();
        if(txt==="UltrAI Chat" || txt==="Chat UI"){
          tw.currentNode.nodeValue="UltrAI Panel";
          break;
        }
      }

      parent.insertBefore(n,a);
    });

    // Show content
    var root=document.querySelector("#root");
    if(root) root.classList.add("ultrai-ready");
  }

  // Fast polling
  var ck=setInterval(function(){
    var r=document.querySelector("#root");
    if(r && r.children.length>0){
      clearInterval(ck);
      run();
      // Watch for ANY DOM change (sheet open/close, navigation, etc)
      new MutationObserver(function(){run()}).observe(document.body,{childList:true,subtree:true});
    }
  },100);

  // Fallback show
  setTimeout(function(){
    var root=document.querySelector("#root");
    if(root) root.classList.add("ultrai-ready");
  },3000);
})();
