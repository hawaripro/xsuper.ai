(function(){
  document.title="UltrAI Dashboard";
  var _k=String.fromCharCode(101,110,111,119,120);
  var _re=new RegExp(_k+'ai','gi');
  var _re2=new RegExp(_k+'\\s*labs','gi');
  var _re3=new RegExp(_k,'gi');

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

    var w=document.createTreeWalker(document.body,NodeFilter.SHOW_TEXT,null,false);
    while(w.nextNode()){
      var v=w.currentNode.nodeValue;
      if(v && v.match(_re3)){
        w.currentNode.nodeValue=v.replace(_re,"UltrAI").replace(_re2,"UltrAI").replace(_re3,"UltrAI");
      }
      if(v && v.trim()==="Chat UI"){
        w.currentNode.nodeValue="UltrAI Chat";
      }
    }

    document.querySelectorAll("a").forEach(function(el){
      var href=el.getAttribute("href")||"";
      var _p=[49,52,51,48].map(function(c){return String.fromCharCode(c)}).join('');
      if(href.indexOf(_p+"/chat")!==-1 || href.indexOf(":"+_p)!==-1){
        el.href="https://ultrai.id/chat";
        el.target="_blank";
      }
    });

    if(document.querySelector("[data-ultrai-link]")) return;

    var chatLink=null;
    document.querySelectorAll("a").forEach(function(a){
      var h=a.getAttribute("href")||"";
      if(h==="https://ultrai.id/chat"){
        chatLink=a;
      }
    });

    if(chatLink){
      var n=chatLink.cloneNode(true);
      n.setAttribute("data-ultrai-link","true");
      n.href="https://ultrai.id/dashboard";
      n.target="_blank";
      n.classList.remove("active");

      var svgs=n.querySelectorAll("svg");
      if(svgs.length>0){
        var s=svgs[0];
        s.setAttribute("viewBox","0 0 256 256");
        s.setAttribute("fill","currentColor");
        s.innerHTML="<path d=\"M216,40H40A16,16,0,0,0,24,56V200a16,16,0,0,0,16,16H216a16,16,0,0,0,16-16V56A16,16,0,0,0,216,40ZM120,176H56V136h64Zm0-56H56V80h64Zm80,56H136V136h64Zm0-56H136V80h64Z\"/>";
      }

      var tw=document.createTreeWalker(n,NodeFilter.SHOW_TEXT,null,false);
      while(tw.nextNode()){
        if(tw.currentNode.nodeValue.trim()==="UltrAI Chat"){
          tw.currentNode.nodeValue="UltrAI Panel";
          break;
        }
      }

      chatLink.parentElement.insertBefore(n,chatLink);
    }

    var root=document.querySelector("#root");
    if(root) root.classList.add("ultrai-ready");
  }

  var ck=setInterval(function(){
    var r=document.querySelector("#root");
    if(r && r.children.length>0){
      clearInterval(ck);
      run();
      new MutationObserver(function(){run()}).observe(document.body,{childList:true,subtree:true});
    }
  },100);

  setTimeout(function(){
    var root=document.querySelector("#root");
    if(root) root.classList.add("ultrai-ready");
  },3000);
})();
