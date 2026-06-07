function savedata(){
	var messagea = document.getElementById('MessageBox').value;
	setCookie('MyCookie', messagea); 
} 
  
function setCookie(name, value){ 
	var d = document; 
	var today = new Date(); 
	var expiry = new Date(today.getTime() + 30 * 24 * 60); 
	d.cookie = name + "=" + escape(value) + "; expires=" + expiry.toGMTString() + "; path=/"; 
}