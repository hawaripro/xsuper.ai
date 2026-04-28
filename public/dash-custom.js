(function(){
  document.title="UltrAI Dashboard";

  function run(){
    if(document.title.indexOf("UltrAI")!==0) document.title="UltrAI Dashboard";

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

    document.querySelectorAll("a").forEach(function(el){
      var href=el.getAttribute("href")||"";
      if(href.indexOf("1430/chat")!==-1 || href.match(/\/chat$/)){
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
  }

  var ck=setInterval(function(){
    var r=document.querySelector("#root");
    if(r && r.children.length>0){
      clearInterval(ck);
      setTimeout(run,1500);
      new MutationObserver(function(){setTimeout(run,500)}).observe(document.body,{childList:true,subtree:true});
    }
  },500);
})();
