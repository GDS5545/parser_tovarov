(function(){
if(window.__uwsPicker){window.__uwsPicker.stop();return;}
function xpath(el){
	if(el.id){return '//*[@id="'+el.id+'"]';}
	if(!el.parentNode||el===document.body){return '/html/body';}
	var ix=0,sibs=el.parentNode.childNodes,tag=el.tagName;
	for(var i=0;i<sibs.length;i++){
		var s=sibs[i];
		if(s===el){return xpath(el.parentNode)+'/'+tag.toLowerCase()+'['+(ix+1)+']';}
		if(s.nodeType===1&&s.tagName===tag){ix++;}
	}
	return xpath(el.parentNode)+'/'+tag.toLowerCase();
}
var panel=document.createElement('div');
panel.style.cssText='position:fixed;bottom:0;left:0;right:0;z-index:2147483647;background:#1d2327;color:#fff;font:13px/1.5 monospace;padding:10px;max-height:40vh;overflow:auto;box-shadow:0 -2px 10px rgba(0,0,0,.4)';
panel.innerHTML='<div style="margin-bottom:6px;font-family:sans-serif;font-weight:bold;">UWS XPath Picker &mdash; click any element on the page. Esc or Stop to finish.</div><div id="uws-log"></div><p><button id="uws-copy" style="margin-right:6px;">Copy all</button><button id="uws-stop">Stop</button></p>';
document.body.appendChild(panel);
var log=panel.querySelector('#uws-log');
var lines=[];
var hovered=null;
function onOver(e){
	if(panel.contains(e.target))return;
	if(hovered)hovered.style.outline='';
	hovered=e.target;
	hovered.style.outline='2px solid #d63638';
}
function onClick(e){
	if(panel.contains(e.target))return;
	e.preventDefault();e.stopPropagation();
	var path=xpath(e.target);
	var label=prompt('Label this element (e.g. name, price, sku, description, images, specifications, categories) or leave blank:','');
	var line=(label?label+': ':'')+path;
	lines.push(line);
	var row=document.createElement('div');
	row.textContent=line;
	log.appendChild(row);
	return false;
}
function onKey(e){ if(e.key==='Escape'){stop();} }
document.addEventListener('mouseover',onOver,true);
document.addEventListener('click',onClick,true);
document.addEventListener('keydown',onKey,true);
panel.querySelector('#uws-copy').addEventListener('click',function(){
	var text=lines.join('\n');
	if(navigator.clipboard){navigator.clipboard.writeText(text);}
	prompt('Copy this:',text);
});
function stop(){
	document.removeEventListener('mouseover',onOver,true);
	document.removeEventListener('click',onClick,true);
	document.removeEventListener('keydown',onKey,true);
	if(hovered)hovered.style.outline='';
	panel.remove();
	window.__uwsPicker=null;
}
panel.querySelector('#uws-stop').addEventListener('click',stop);
window.__uwsPicker={stop:stop};
})();
