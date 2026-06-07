function popup(mylink, windowname, w, h)
{
	if (! window.focus)return true;
	var href;
	if (typeof(mylink) == 'string')
   		href=mylink;
	else
		href=mylink.href;
	window.open(href, windowname, 'width='+w+',height='+h+',scrollbars=yes');
	return false;
}

function r(hval)
{
	document.frmMain.bgColor.value=hval;
}

function s(hval)
{
	document.frmMain.textColor.value=hval;
}
	
function l()
{
	//do nothing
}

function hideShow( sID )
{
	var oElement;
	
	oElement = document.getElementById(sID);
	
	if ( oElement.lastShowID != null )
	{
		if ( oElement.lastShowID == sID )
		{
			oElement.lastShowID = null;
		}
		else
		{
			var hideID = oElement.lastShowID;
			oElement.lastShowID = null;
			hideShow(hideID);
		}
	}
		
	if ( getDisplayValue( sID ) == '' ) 
	{  
		hideElement(sID);
		return(false);
	}
		
	showElement(sID);
	oElement.lastShowID = sID;
	return(false);
}

function hideShowBelow( sID, oParent )
{
	var oElement;
	
	oElement = document.getElementById(sID);
	
	var pX = brd_fnGetLeftPos(oParent) + 'px';
	var pY = brd_fnGetTopPos(oParent) + brd_fnGetObjectHeight(oParent) + 'px';
	
	hideShow( sID ); 
	
	oElement.style.left = pX;
	oElement.style.top = pY;
}

function showElement( sID )
{
	setDisplayValue( sID, '' );
}

function hideElement( sID )
{
	setDisplayValue( sID, 'none' );
}

function getDisplayValue( sID )
{
	var oElement = document.getElementById(sID);
	
	if ( oElement )
	{
		if( oElement.style ) 
		{  
			return( oElement.style.display );  // IE
		} 
		else if( oElement.display ) 
		{
			return( oElement.display ); // MOZILLA
		}
	}
	
	return( null );
}

function setDisplayValue( sID, sValue )
{
	var oElement = document.getElementById(sID);
	
	if ( oElement )
	{
		if( oElement.style ) 
		{  
			oElement.style.display = sValue;  // IE
		} 
		else if( oElement.display ) 
		{
			oElement.display = sValue; // MOZILLA
		}
	} 
}

function brd_fnGetLeftPos(oElement)
{
	var left = oElement.offsetLeft;
	
	while( (oElement = oElement.offsetParent) != null )
	{
		left += oElement.offsetLeft;
	}
	
	return left;
}

function brd_fnGetTopPos(oElement)
{
	var top = oElement.offsetTop;
	
	while( (oElement = oElement.offsetParent) != null )
	{
		top += oElement.offsetTop;
	}
	
	return top;
}

function brd_fnGetObjectHeight(oElement)
{
	if ( oElement.offsetHeight )
	{
		return( oElement.offsetHeight );
	}
	else
	{
		return( oElement.style.height );
	}
}


function brn_fnUnselectable(oElement)
{
	if ( is_ie4 )
	{
		return;
	}
	else if ( typeof(oElement.tagName) != 'undefined' )
	{
		if ( oElement.hasChildNodes() )
		{
			for ( var i = 0; i < oElement.childNodes.length; i++ )
			{
				brn_fnUnselectable(oElement.childNodes[i]);
			}
		}
		
		oElement.unselectable = true;
	}
}
