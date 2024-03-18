<xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform">

    <xsl:template match="/">
        <items>
            <xsl:apply-templates select="duplicateSets/duplicateSet"/>
        </items>
    </xsl:template>

    <xsl:template match="duplicateSet">
        <xsl:variable name="ids" select="ids/id"/>
        <xsl:for-each select="$ids">
            <item id="{.}">
                <xsl:copy-of select="../value"/>
            </item>
        </xsl:for-each>
    </xsl:template>

</xsl:stylesheet>