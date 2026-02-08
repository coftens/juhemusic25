using System;
using System.Collections.Generic;
using System.IO;
using System.Reflection;
using Avalonia.Controls;

namespace MusicLyricApp.Models;

public static class Constants
{
    private const string AppFolderName = "MusicLyricApp";
    private const string ConfigFileName = "MusicLyricAppSetting.json";

    /// <summary>
    /// 获取用户配置文件的完整路径，确保目录存在。
    /// </summary>
    public static string GetConfigFilePath()
    {
        string basePath = Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData);

        // macOS/Linux 会自动转换为 ~/Library/Application Support 或 ~/.config
        string configDir = Path.Combine(basePath, AppFolderName);

        // 确保目录存在
        Directory.CreateDirectory(configDir);

        return Path.Combine(configDir, ConfigFileName);
    }

    public static WindowIcon GetIcon(string name)
    {
        var assembly = Assembly.GetExecutingAssembly();
        var resourceName = "MusicLyricApp.Resources." + name + ".ico";

        using var stream = assembly.GetManifestResourceStream(resourceName);
        
        return new WindowIcon(stream);
    }

    public const int BatchQuerySize = 300;

    public const int SleepMsBetweenBatchQuery = 500;

    public static class HelpTips
    {
        public const string Prefix = "【提示区】";

        public enum TypeEnum
        {
            /// <summary>
            /// 默认实例
            /// </summary>
            DEFAULT,

            /// <summary>
            /// 时间戳设置
            /// </summary>
            TIME_STAMP_SETTING,

            /// <summary>
            /// 输出设置
            /// </summary>
            OUTPUT_SETTING
        }

        public static string GetContent(TypeEnum typeEnum)
        {
            var list = new List<string> { Prefix };

            switch (typeEnum)
            {
                case TypeEnum.TIME_STAMP_SETTING:
                    list.Add("您可自行调整『LRC/SRT 时间戳』配置，系统预设的元变量有：");
                    list.Add("HH -> 小时，采用 24 小时制，结果为 0 ~ 23");
                    list.Add("mm -> 分钟，输出区间 [0,59]");
                    list.Add("ss -> 秒，输出区间 [0,59]");
                    list.Add("S -> 毫秒，仅保留一位，输出区间 [0,9]");
                    list.Add("SS -> 毫秒，仅保留两位，输出区间 [0,99]");
                    list.Add("SSS -> 毫秒，输出区间 [0,999]");
                    list.Add("当毫秒的占位符为 S 或 SS 时，『毫秒截位规则』配置生效");
                    break;
                case TypeEnum.OUTPUT_SETTING:
                    list.Add("您可自行调整『保存文件名』配置，系统预设的元变量有：");
                    list.Add("${id} -> 歌曲 ID");
                    list.Add("${index} -> 歌曲位于搜索结果中的索引序号");
                    list.Add("${name} -> 歌曲名");
                    list.Add("${singer} -> 歌手名");
                    list.Add("${album} -> 专辑名");
                    list.Add("-----");
                    list.Add("系统预设函数：");
                    list.Add("$fillLength(content,symbol,length)");
                    list.Add("长度填充，其中 content 表示操作的内容，symbol 表示填充的内容，length 表示填充的长度。" +
                             "例如 $fillLength(${index},0,3) 表示对于 ${index} 的结果，长度填充到 3 位，使用 0 填充" +
                             "【即 1 -> 001, 12 -> 012, 123 -> 123, 1234 -> 1234】");
                    list.Add("-----");
                    list.Add("您可自行决定输出哪些歌词类型，通过勾选复选框进行启用和关闭");
                    list.Add("拖拽最左侧的箭头可以调整输出的顺序");
                    list.Add("罗马音功能需要安装罗马音插件；非原始译文类型需要指定翻译 API");
                    break;
                case TypeEnum.DEFAULT:
                default:
                    break;
            }

            return string.Join(Environment.NewLine + Environment.NewLine, list);
        }
    }
}