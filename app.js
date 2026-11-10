(function(){
 const upi=document.getElementById('upiInput'), qr=document.getElementById('qrcode'), hint=document.getElementById('qrHint');
 if(upi&&qr){
   function draw(){
     qr.innerHTML='';
     const id=upi.value.trim();
     if(!/^[A-Za-z0-9._-]{2,256}@[A-Za-z0-9.-]{2,64}$/.test(id)){if(hint)hint.textContent='Enter and save a valid UPI ID to preview its QR.';return;}
     const uri='upi://pay?pa='+encodeURIComponent(id)+'&pn='+encodeURIComponent('FAMPAY.XL Merchant')+'&cu=INR';
     if(window.QRCode){new QRCode(qr,{text:uri,width:150,height:150,correctLevel:QRCode.CorrectLevel.M});if(hint)hint.textContent='QR preview generated from '+id;}
     else if(hint)hint.textContent='QR library could not load. Check your internet connection.';
   }
   draw(); upi.addEventListener('input',draw);
 }
 window.makeLink=function(){
   const amount=Number(document.getElementById('linkAmount').value), note=document.getElementById('linkNote').value;
   const target=document.getElementById('linkResult');
   if(!amount||amount<1){target.textContent='Enter a valid amount.';return;}
   target.textContent='Demo checkout preview: '+location.origin+location.pathname+'?demo_checkout='+encodeURIComponent(amount)+'&note='+encodeURIComponent(note)+' — no payment is collected.';
 };
})();