using JetBrains.Annotations;
using MusicLyricApp.Core.Utils;

namespace MusicLyricAppTest.Core.Utils;

[TestSubject(typeof(VerbatimLyricUtils))]
public class VerbatimLyricUtilsTest
{

    [Fact]
    public void Convert_BasicLine_WithThreeTimeStamps()
    {
        string input = "[00:04.260]遠[00:04.940]い[00:05.150]夏";
        string expected = "[00:04.260]<00:04.260>遠<00:04.940>い<00:05.150>夏";

        string result = VerbatimLyricUtils.ConvertVerbatimLyricFromBasicToA2Mode(input);

        Assert.Equal(expected, result);
    }

    [Fact]
    public void Convert_TwoDecimalPrecision_ShouldKeepFormat()
    {
        string input = "[00:04.26]遠[00:04.94]い[00:05.15]夏";
        string expected = "[00:04.26]<00:04.26>遠<00:04.94>い<00:05.15>夏";

        string result = VerbatimLyricUtils.ConvertVerbatimLyricFromBasicToA2Mode(input);

        Assert.Equal(expected, result);
    }

    [Fact]
    public void Convert_SingleTimeStampLine()
    {
        string input = "[00:10.50]夏";
        string expected = "[00:10.50]<00:10.50>夏";

        string result = VerbatimLyricUtils.ConvertVerbatimLyricFromBasicToA2Mode(input);

        Assert.Equal(expected, result);
    }

    [Fact]
    public void Convert_NoMillisecond_ShouldWork()
    {
        string input = "[00:10]夏";
        string expected = "[00:10]<00:10>夏";

        string result = VerbatimLyricUtils.ConvertVerbatimLyricFromBasicToA2Mode(input);

        Assert.Equal(expected, result);
    }

    [Fact]
    public void Convert_MixedTimeFormats_ShouldWork()
    {
        string input = "[00:10]夏[00:15.5]の[00:20.50]日";
        string expected = "[00:10]<00:10>夏<00:15.5>の<00:20.50>日";

        string result = VerbatimLyricUtils.ConvertVerbatimLyricFromBasicToA2Mode(input);

        Assert.Equal(expected, result);
    }
    
    [Fact]
    public void Convert_EmptyLine_ReturnsEmpty()
    {
        string input = "";
        string expected = "";

        string result = VerbatimLyricUtils.ConvertVerbatimLyricFromBasicToA2Mode(input);

        Assert.Equal(expected, result);
    }

    [Fact]
    public void Convert_NoTimeStamp_ShouldReturnOriginal()
    {
        string input = "遠い夏";
        string expected = "遠い夏";

        string result = VerbatimLyricUtils.ConvertVerbatimLyricFromBasicToA2Mode(input);

        Assert.Equal(expected, result);
    }
}